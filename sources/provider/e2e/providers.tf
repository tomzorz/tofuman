# SPDX-License-Identifier: MIT
#
# The provider of the end-to-end procedure (spec section 18). It takes the endpoint and the API
# key from TOFUMAN_ENDPOINT and TOFUMAN_API_KEY; e2e.ps1 runs it.

terraform {
  required_providers {
    tofuman = {
      source = "tomzorz/tofuman"
    }
  }
}

provider "tofuman" {}
