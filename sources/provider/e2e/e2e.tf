# SPDX-License-Identifier: MIT
#
# The throwaway container of the end-to-end procedure (spec section 18): a path, a port, a
# variable, a secret, and a label. e2e.sh renames it through var.name.

variable "name" {
  type    = string
  default = "tofuman-e2e"
}

resource "tofuman_container" "e2e" {
  name       = var.name
  repository = "busybox:latest"
  network    = "bridge"
  autostart  = true
  post_args  = ["sleep", "86400"]

  path {
    host_path      = "/mnt/user/appdata/tofuman-e2e"
    container_path = "/data"
  }
  port {
    host_port      = "18099"
    container_port = "8080"
  }
  variable {
    key   = "GREETING"
    value = "hello"
  }
  secret {
    key   = "TOKEN"
    value = "not-a-real-secret"
  }
  label {
    key   = "com.example.purpose"
    value = "tofuman-e2e"
  }
}
