# shellcheck shell=bash
# Managed by the ddev-fleet installer (install/steps/35-shell.sh): changes are overwritten on the next install run.
# Opt out with --no-shell-profile; the uninstaller removes this file and the hooks that source it.
#
# Interactive shell prompt, colours and aliases for every account on a fleet host. This file is SOURCED, not
# executed, so every line must be a comment or valid shell.
#
# It is read from /etc/profile.d (interactive login shells), from /etc/bash.bashrc and from the end of the ~/.bashrc
# of root, the fleet user and the account that ran the installer (non-login shells such as `su fleet` read only those;
# Debian's stock ~/.bashrc sets PS1 after the system files, so the hook at its end is what makes the prompt stick).

# Never touch non-interactive shells (scp, rsync, `ssh host cmd`, cron).
case $- in
  *i*) ;;
  *) return 0 ;;
esac

# Bold cyan host, " - ", bold red "<user> $", ": ", bold blue cwd, " > ": "I am on the fleet server" at a glance, for
# root and for fleet alike. \[ \] wrap every escape so bash leaves it out of the prompt width.
PS1='\[\e[1;36m\]\h\[\e[0m\] - \[\e[1;31m\]\u $\[\e[0m\]: \[\e[1;34m\]\w\[\e[0m\] > '

export CLICOLOR=1
export EDITOR=nano

alias l='ls -lah'
alias ls='ls --color=auto'
