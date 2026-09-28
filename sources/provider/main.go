// SPDX-License-Identifier: MIT

// terraform-provider-tofuman is the OpenTofu provider of tofuman.
package main

import (
	"context"
	"flag"
	"log"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"

	"github.com/tomzorz/tofuman/sources/provider/internal/provider"
)

// version is set at release time with -ldflags "-X main.version=<version>".
var version = "dev"

func main() {
	var debug bool
	flag.BoolVar(&debug, "debug", false, "serve the provider for a debugger such as delve")
	flag.Parse()
	err := providerserver.Serve(context.Background(), provider.New(version), providerserver.ServeOpts{
		Address: "registry.opentofu.org/tomzorz/tofuman",
		Debug:   debug,
	})
	if err != nil {
		log.Fatal(err)
	}
}
