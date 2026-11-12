# Try Fleet on a DigitalOcean Droplet: one Droplet that runs deploy/cloud-init/digitalocean.yaml on first boot, plus a
# firewall for SSH, HTTP and HTTPS. Guide: docs/guides/11-try-on-digitalocean.md.
#
#   export DIGITALOCEAN_TOKEN=...            # a token with write access, never committed
#   terraform init && terraform apply -var 'ssh_key_name=my-key'
#   terraform destroy                        # when you are done: removes the Droplet and the firewall

terraform {
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.107"
    }
  }
}

variable "ssh_key_name" {
  description = "Name of an SSH key already uploaded to your DigitalOcean account"
  type        = string
}

variable "region" {
  description = "Droplet region slug"
  type        = string
  default     = "fra1"
}

variable "size" {
  description = "Droplet size slug: 2 vCPU / 8 GB is the minimum"
  type        = string
  default     = "s-2vcpu-8gb-160gb-intel"
}

variable "ssh_source_addresses" {
  description = "Addresses allowed to reach SSH (CIDR); narrow this to your own address when you can"
  type        = list(string)
  default     = ["0.0.0.0/0", "::/0"]
}

data "digitalocean_ssh_key" "me" {
  name = var.ssh_key_name
}

resource "digitalocean_droplet" "fleet" {
  name      = "fleet-test"
  image     = "debian-13-x64"
  region    = var.region
  size      = var.size
  ssh_keys  = [data.digitalocean_ssh_key.me.id]
  user_data = file("${path.module}/../../cloud-init/digitalocean.yaml")
  tags      = ["fleet-test"]
}

resource "digitalocean_firewall" "fleet" {
  name        = "fleet-test"
  droplet_ids = [digitalocean_droplet.fleet.id]

  inbound_rule {
    protocol         = "tcp"
    port_range       = "22"
    source_addresses = var.ssh_source_addresses
  }
  inbound_rule {
    protocol         = "tcp"
    port_range       = "80"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }
  inbound_rule {
    protocol         = "tcp"
    port_range       = "443"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }
  outbound_rule {
    protocol              = "tcp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
  outbound_rule {
    protocol              = "udp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
  outbound_rule {
    protocol              = "icmp"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
}

output "droplet_ip" {
  value = digitalocean_droplet.fleet.ipv4_address
}

output "next_step" {
  value = "Wait about 10 minutes, then run: ssh root@${digitalocean_droplet.fleet.ipv4_address} cat /root/FLEET-READY.txt"
}
