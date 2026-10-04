// SPDX-License-Identifier: MIT

// Package client speaks the GraphQL interface of the tofuman API module (spec section 15):
// plain HTTP POSTs to /graphql with the API key in the header x-api-key.
package client

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/hashicorp/terraform-plugin-log/tflog"
)

// Config says how to reach the API module.
type Config struct {
	// Endpoint is the base URL of the server; the requests go to /graphql under it.
	Endpoint string
	APIKey   string
	// CACertificate is PEM text, trusted next to the system roots.
	CACertificate string
	Insecure      bool
	// Version is the version of the provider, which each request names (REQ-PRV-14).
	Version string
}

// Client sends the queries and mutations of the API module.
type Client struct {
	url     string
	apiKey  string
	version string
	http    *http.Client
}

// ProviderHeader carries the version of the provider to the audit log (REQ-PRV-14).
const ProviderHeader = "x-tofuman-provider"

const (
	// requestTimeout covers one request. nginx on the server ends a request after 60 seconds
	// without a response byte, which is why every mutation returns before its work is done.
	requestTimeout = 90 * time.Second
	// maxPollInterval is the longest wait between two polls of an operation.
	maxPollInterval = 5 * time.Second
	maxAnswer       = 16 << 20
)

// New checks the configuration and builds a client. It sends nothing.
func New(config Config) (*Client, error) {
	endpoint, err := url.Parse(config.Endpoint)
	if err != nil || (endpoint.Scheme != "https" && endpoint.Scheme != "http") || endpoint.Host == "" {
		return nil, fmt.Errorf("the endpoint %q is not an http or https URL", config.Endpoint)
	}
	if config.APIKey == "" {
		return nil, errors.New("the API key is empty")
	}
	transport, err := Transport(config.CACertificate, config.Insecure)
	if err != nil {
		return nil, err
	}
	return &Client{
		url:     strings.TrimSuffix(endpoint.String(), "/") + "/graphql",
		apiKey:  config.APIKey,
		version: config.Version,
		http:    &http.Client{Transport: transport, Timeout: requestTimeout},
	}, nil
}

// Transport verifies the server against the system roots and caCertificate, unless insecure
// is set (REQ-PRV-12).
func Transport(caCertificate string, insecure bool) (*http.Transport, error) {
	roots, err := x509.SystemCertPool()
	if err != nil {
		roots = x509.NewCertPool()
	}
	if caCertificate != "" && !roots.AppendCertsFromPEM([]byte(caCertificate)) {
		return nil, errors.New("ca_certificate holds no PEM certificate")
	}
	base, ok := http.DefaultTransport.(*http.Transport)
	if !ok {
		return nil, errors.New("http.DefaultTransport is not an *http.Transport")
	}
	transport := base.Clone()
	transport.TLSClientConfig = &tls.Config{
		RootCAs:            roots,
		MinVersion:         tls.VersionTLS12,
		InsecureSkipVerify: insecure, //nolint:gosec // the person set insecure
	}
	return transport, nil
}

// Error is an error that the server answered with: a refusal, a failure, or an error of
// unraid-api itself.
type Error struct {
	Message string
	// Code is extensions.code: TOFUMAN_REFUSED, TOFUMAN_FAILED, TOFUMAN_UNKNOWN_OPERATION, or a
	// code of unraid-api.
	Code string
	// Checks names each failed check of a refusal (REQ-MUT-3).
	Checks []string
	// PolicyGaps counts the checks that a change of the policy would pass (REQ-PRV-18).
	PolicyGaps int
}

func (e *Error) Error() string {
	if len(e.Checks) > 1 {
		return strings.Join(e.Checks, "; ")
	}
	return e.Message
}

// Refused reports whether the server refused the request, which then changed nothing.
func (e *Error) Refused() bool {
	return e.Code == "TOFUMAN_REFUSED"
}

type graphQLError struct {
	Message    string `json:"message"`
	Extensions struct {
		Code          string          `json:"code"`
		Errors        []string        `json:"errors"`
		PolicyGaps    int             `json:"policyGaps"`
		OriginalError json.RawMessage `json:"originalError"`
	} `json:"extensions"`
}

// fieldName picks the field of the tofuman namespace that a request asks for, such as
// createContainer or operation, for the log.
var fieldName = regexp.MustCompile(`tofuman\s*\{\s*(\w+)`)

// do sends one request, and logs it at the level DEBUG with its duration (REQ-PRV-17). It never
// puts the API key or the variables, which can hold the values of secrets, into an error or a
// log line (REQ-PRV-11).
func (c *Client) do(ctx context.Context, query string, variables map[string]any, data any) error {
	field := "request"
	if match := fieldName.FindStringSubmatch(query); match != nil {
		field = match[1]
	}
	started := time.Now()
	err := c.send(ctx, query, variables, data)
	fields := map[string]any{"field": field, "ms": time.Since(started).Milliseconds()}
	if err != nil {
		fields["error"] = err.Error()
	}
	tflog.Debug(ctx, "tofuman request", fields)
	return err
}

func (c *Client) send(ctx context.Context, query string, variables map[string]any, data any) error {
	body, err := json.Marshal(map[string]any{"query": query, "variables": variables})
	if err != nil {
		return err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("x-api-key", c.apiKey)
	if c.version != "" {
		request.Header.Set(ProviderHeader, c.version)
	}
	response, err := c.http.Do(request)
	if err != nil {
		return fmt.Errorf("POST %s: %w", c.url, err)
	}
	defer response.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(response.Body, maxAnswer))
	if err != nil {
		return fmt.Errorf("POST %s: reading the answer: %w", c.url, err)
	}
	var answer struct {
		Data   json.RawMessage `json:"data"`
		Errors []graphQLError  `json:"errors"`
	}
	if err := json.Unmarshal(raw, &answer); err != nil {
		return fmt.Errorf("POST %s answered HTTP %d without GraphQL: %s", c.url, response.StatusCode, snippet(raw))
	}
	if len(answer.Errors) > 0 {
		return errorFrom(answer.Errors)
	}
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("POST %s answered HTTP %d: %s", c.url, response.StatusCode, snippet(raw))
	}
	if err := json.Unmarshal(answer.Data, data); err != nil {
		return fmt.Errorf("POST %s answered with data of an unexpected shape: %w", c.url, err)
	}
	return nil
}

func errorFrom(errs []graphQLError) *Error {
	first := errs[0]
	result := &Error{Message: first.Message, Code: first.Extensions.Code, Checks: first.Extensions.Errors, PolicyGaps: first.Extensions.PolicyGaps}
	if details := originalDetails(first.Extensions.OriginalError, first.Message); len(details) > 0 && len(result.Checks) == 0 {
		result.Message += ": " + strings.Join(details, "; ")
	}
	for _, other := range errs[1:] {
		result.Message += "; " + other.Message
	}
	return result
}

// originalDetails reads what NestJS puts into extensions.originalError: the message of the
// exception it caught, a string or a list. A ValidationPipe lists each property it rejected,
// while the top message only says "Bad Request Exception".
func originalDetails(raw json.RawMessage, top string) []string {
	var original struct {
		Message json.RawMessage `json:"message"`
	}
	if len(raw) == 0 || json.Unmarshal(raw, &original) != nil || len(original.Message) == 0 {
		return nil
	}
	var list []string
	if json.Unmarshal(original.Message, &list) == nil {
		return list
	}
	var text string
	if json.Unmarshal(original.Message, &text) == nil && text != "" && text != top {
		return []string{text}
	}
	return nil
}

func snippet(raw []byte) string {
	const most = 300
	text := strings.TrimSpace(string(raw))
	if len(text) > most {
		return text[:most] + "…"
	}
	return text
}
