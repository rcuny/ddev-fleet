# Try Fleet on DigitalOcean

Use this guide to get a throw-away Fleet in about 15 minutes, without owning a domain. You paste one file when you create a Droplet, wait, read a one-time link and open it. It is for trying Fleet out: for a host you keep, follow [install](01-install.md) with your own domain.

DigitalOcean neither builds nor supports Fleet; this is an ordinary Debian 13 Droplet running the normal installer.

## What you need

- A DigitalOcean account and an SSH key uploaded to it.
- A Debian 13 Droplet of at least **2 vCPU / 8 GB** (the Basic `s-2vcpu-8gb-160gb-intel` size, about USD 0.06 per hour; check the current price on DigitalOcean's pricing page).

## Create the Droplet

1. In the control panel choose **Create > Droplets**, a region near you and the image **Debian 13**.
2. Choose the size above and your SSH key as the authentication method.
3. Open **Advanced Options**, tick **Add Initialization scripts** and paste the whole content of [`deploy/cloud-init/digitalocean.yaml`](../../deploy/cloud-init/digitalocean.yaml). Optionally set `ACME_EMAIL` (Let's Encrypt expiry notices) near the top of the pasted script. Leave `FLEET_DOMAIN='auto'` unless you own a domain (see below).
4. Create the Droplet.

On first boot the file installs Fleet from the latest `v1.*` release tag, enables a firewall for SSH, HTTP and HTTPS only, and writes a one-time setup link. The install takes about 7 minutes on this size.

## Read the link and finish the setup

When it is done, the Droplet console (**Access > Droplet Console**) and `/etc/motd` at your next SSH login say so, and the link itself is in the root-only file `/root/FLEET-READY.txt`: read it with `sudo cat /root/FLEET-READY.txt`. If the install failed, the same places say so and name the log. If nothing is there yet, follow the progress with `tail -f /var/log/fleet-first-boot.log`; the whole run is also in `/var/log/cloud-init-output.log`. Neither log contains the link.

Open the link first. It is valid for 24 hours or until you finish the wizard, and **whoever holds it creates the administrator account**: do not paste it anywhere public. Then follow the [setup wizard and first project](02-first-project.md).

## About the `nip.io` name

With `FLEET_DOMAIN='auto'` the installer calls the host `<ip-with-dashes>.nip.io`, for example `203-0-113-7.nip.io` for the address `203.0.113.7`, and instances `<instance>.203-0-113-7.nip.io`. The name works because [nip.io](https://nip.io) answers every such name with the address inside it, so there is no DNS to set up. Fleet's default `http01` TLS mode then asks Let's Encrypt for one certificate per host name (no wildcard certificate is possible for these names).

nip.io is a free service run by a third party, so treat it as a test convenience, never as a production name: if it is down or blocked, the host's name stops resolving. All users of `nip.io` names share Let's Encrypt's certificate rate limits for that domain, so a certificate request can be refused when many people test at once; this guide makes no claim about the service's Public Suffix List status. Your own domain (`--domain=<fqdn>`) is the fallback. Do not switch to `sslip.io` to work around a problem: it shares a Let's Encrypt rate-limit bucket with many other users. For anything you keep, set `FLEET_DOMAIN` to your own domain with an A record for it and for `auth.<your domain>` (or one wildcard record) pointing at the Droplet, or re-run the installer later with `--domain=<fqdn>`. The wildcard certificate through OVH DNS stays an explicit option in the setup wizard, never the default.

## The same file from the command line

With `doctl` (an API token with write access, `doctl auth init`):

```bash
doctl compute droplet create fleet-test \
  --image debian-13-x64 --size s-2vcpu-8gb-160gb-intel --region fra1 \
  --ssh-keys <ssh-key-id-or-fingerprint> \
  --user-data-file deploy/cloud-init/digitalocean.yaml --wait
```

With Terraform, [`deploy/terraform/digitalocean/`](../../deploy/terraform/digitalocean/main.tf) creates the Droplet and a Cloud Firewall from the same file:

```bash
export DIGITALOCEAN_TOKEN=<your token>
cd deploy/terraform/digitalocean
terraform init
terraform apply -var 'ssh_key_name=my-key'
```

## Safety notes

- The file contains no secret. The only input is the optional contact address.
- The Droplet's own firewall (`ufw`) allows ports 22, 80 and 443. If you later publish a named port, `fleet port:firewall` lists what to open. Restrict SSH to your address in a DigitalOcean Cloud Firewall if you can.
- The hardening option is not applied; see [secure the host](08-secure-the-host.md) before you keep the host.

## Teardown and cost

Billing is hourly until the Droplet is destroyed; a stopped Droplet is still billed. When you are done:

```bash
doctl compute droplet delete fleet-test      # or: terraform destroy
```

or choose **Destroy** on the Droplet in the control panel. Snapshots, volumes, reserved IPs and Cloud Firewalls are separate objects: remove any you created. The Let's Encrypt certificates simply expire.
