// SPDX-License-Identifier: MIT

package client

import (
	"encoding/pem"
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
