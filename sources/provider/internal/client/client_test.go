// SPDX-License-Identifier: MIT

package client

import (
	"context"
	"encoding/json"
	"encoding/pem"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// REQ-PRV-12 against a real TLS server with a certificate that no system root signed.
func TestTransportTrustsOnlyWhatItIsTold(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()
	ca := string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw}))

	get := func(caCertificate string, insecure bool) error {
		transport, err := Transport(caCertificate, insecure)
		if err != nil {
			t.Fatalf("Transport: %v", err)
		}
		response, err := (&http.Client{Transport: transport}).Get(server.URL)
		if err == nil {
			response.Body.Close()
		}
		return err
	}

	if err := get("", false); err == nil || !strings.Contains(err.Error(), "certificate") {
		t.Errorf("an unknown certificate passed without ca_certificate: %v", err)
	}
	if err := get(ca, false); err != nil {
		t.Errorf("the certificate in ca_certificate was refused: %v", err)
	}
	if err := get("", true); err != nil {
		t.Errorf("insecure still checked the certificate: %v", err)
	}
	if _, err := Transport("not a certificate", false); err == nil {
		t.Error("ca_certificate without PEM was accepted")
	}
}

// unraid-api answered the first mutation on a server with only "Bad Request Exception" in the
// message; the properties that its ValidationPipe rejected sit in extensions.originalError.
func TestErrorKeepsTheDetailsOfAnException(t *testing.T) {
	var answer struct {
		Errors []graphQLError `json:"errors"`
	}
	body := `{"errors":[{"message":"Bad Request Exception","extensions":{"code":"BAD_REQUEST","originalError":{"message":["property name should not exist","property repository should not exist"],"error":"Bad Request","statusCode":400}}}]}`
	if err := json.Unmarshal([]byte(body), &answer); err != nil {
		t.Fatal(err)
	}
	got := errorFrom(answer.Errors).Error()
	if !strings.Contains(got, "property name should not exist") || !strings.Contains(got, "property repository should not exist") {
		t.Errorf("the details of the exception are lost: %q", got)
	}
}

// REQ-PRV-18: a refusal says how many of its checks a change of the policy would pass.
func TestRefusalCountsPolicyGaps(t *testing.T) {
	var answer struct {
		Errors []graphQLError `json:"errors"`
	}
	body := `{"errors":[{"message":"network bond0 is not in the policy's networks","extensions":{"code":"TOFUMAN_REFUSED","errors":["network bond0 is not in the policy's networks"],"policyGaps":1}}]}`
	if err := json.Unmarshal([]byte(body), &answer); err != nil {
		t.Fatal(err)
	}
	if got := errorFrom(answer.Errors); !got.Refused() || got.PolicyGaps != 1 {
		t.Errorf("the refusal lost its policy gaps: %+v", got)
	}
}

// A server that records each request it gets, and answers each one with answer.
func recorder(t *testing.T, answer string) (*Client, *[]*http.Request, *[]string) {
	t.Helper()
	var requests []*http.Request
	var bodies []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		requests = append(requests, r)
		bodies = append(bodies, string(body))
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(answer))
	}))
	t.Cleanup(server.Close)
	c, err := New(Config{Endpoint: server.URL, APIKey: "key", Version: "1.2.3"})
	if err != nil {
		t.Fatal(err)
	}
	return c, &requests, &bodies
}

// REQ-PRV-14: each request names the version of the provider.
func TestRequestsNameTheProviderVersion(t *testing.T) {
	c, requests, _ := recorder(t, `{"data":{"tofuman":{"container":null}}}`)
	if _, err := c.Container(context.Background(), "x"); err != nil {
		t.Fatal(err)
	}
	if got := (*requests)[0].Header.Get(ProviderHeader); got != "1.2.3" {
		t.Errorf("the request named the version %q", got)
	}
}

// The default start check goes unnamed, so that a plugin from before the start check still
// takes the mutation.
func TestStartCheckGoesUnnamedByDefault(t *testing.T) {
	c, _, bodies := recorder(t, `{"data":{"tofuman":{"createContainer":{"id":"o","state":"QUEUED","step":null,"error":null,"container":null}}}}`)
	if _, err := c.Create(context.Background(), Definition{}, nil); err != nil {
		t.Fatal(err)
	}
	seconds := 30
	if _, err := c.Create(context.Background(), Definition{}, &seconds); err != nil {
		t.Fatal(err)
	}
	if strings.Contains((*bodies)[0], "startCheckSeconds") {
		t.Errorf("the default start check was named: %s", (*bodies)[0])
	}
	if !strings.Contains((*bodies)[1], `startCheckSeconds: $startCheckSeconds`) || !strings.Contains((*bodies)[1], `"startCheckSeconds":30`) {
		t.Errorf("a start check of 30 seconds went missing: %s", (*bodies)[1])
	}
}

func TestNewRefusesAnUnusableConfig(t *testing.T) {
	for _, config := range []Config{
		{Endpoint: "192.0.2.10", APIKey: "key"},
		{Endpoint: "ftp://192.0.2.10", APIKey: "key"},
		{Endpoint: "https://", APIKey: "key"},
		{Endpoint: "https://192.0.2.10", APIKey: ""},
	} {
		if _, err := New(config); err == nil {
			t.Errorf("New accepted %+v", config)
		}
	}
	c, err := New(Config{Endpoint: "https://192.0.2.10/", APIKey: "key"})
	if err != nil {
		t.Fatal(err)
	}
	if c.url != "https://192.0.2.10/graphql" {
		t.Errorf("the requests go to %s", c.url)
	}
}
