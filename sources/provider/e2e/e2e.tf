# SPDX-License-Identifier: MIT
#
# The throwaway container of the end-to-end procedure (spec section 18): a path, a port, a
# variable, a secret, and a label. e2e.ps1 renames it, gives it a flag that tofuman does not
# know, and gives it a command that exits at once, through the variables.

variable "name" {
  type    = string
  default = "tofuman-e2e"
}

variable "extra_params" {
  type    = list(string)
  default = []
}

variable "command" {
  type    = list(string)
  default = ["sleep", "86400"]
}

resource "tofuman_container" "e2e" {
  name         = var.name
  repository   = "busybox:latest"
  network      = "bridge"
  autostart    = true
  extra_params = var.extra_params
  post_args    = var.command

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
