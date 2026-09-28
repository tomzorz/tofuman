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
	"strings"
	"time"
)

// Config says how to reach the API module.
type Config struct {
	// Endpoint is the base URL of the server; the requests go to /graphql under it.
	Endpoint string
	APIKey   string
	// CACertificate is PEM text, trusted next to the system roots.
	CACertificate string
	Insecure      bool
}

// Client sends the queries and mutations of the API module.
type Client struct {
	url    string
	apiKey string
	http   *http.Client
}

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
		url:    strings.TrimSuffix(endpoint.String(), "/") + "/graphql",
		apiKey: config.APIKey,
		http:   &http.Client{Transport: transport, Timeout: requestTimeout},
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
		Code   string   `json:"code"`
		Errors []string `json:"errors"`
	} `json:"extensions"`
}

// do sends one request. It never puts the API key or the variables, which can hold the values
// of secrets, into an error (REQ-PRV-11).
func (c *Client) do(ctx context.Context, query string, variables map[string]any, data any) error {
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
	result := &Error{Message: first.Message, Code: first.Extensions.Code, Checks: first.Extensions.Errors}
	for _, other := range errs[1:] {
		result.Message += "; " + other.Message
	}
	return result
}

func snippet(raw []byte) string {
	const most = 300
	text := strings.TrimSpace(string(raw))
	if len(text) > most {
		return text[:most] + "…"
	}
	return text
}
