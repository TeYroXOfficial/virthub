# VirtHub: budowa szablonu Windows Server na węźle KVM.
#
# Pliki instalacji (Autounattend.xml, sysprep, cloudbase-init, skrypty
# PowerShell) pochodzą z publicznego repozytorium VirtFusion
# (bitbucket.org/virtfusion-public/packer) — agent pobiera je przy każdej
# budowie i podmienia w nich hasło budowy na losowe. Ten plik opisuje tylko
# maszynę budującą i kolejność kroków, tak jak windows-server.pkr.hcl VirtFusion,
# z dwiema różnicami: WinRM i VNC słuchają wyłącznie na 127.0.0.1, a obraz
# ISO i sterowniki podaje agent (retail/SPLA albo wersja ewaluacyjna).

packer {
  required_plugins {
    qemu = {
      source  = "github.com/hashicorp/qemu"
      version = "~> 1.1"
    }
  }
}

variable "files_dir" {
  type        = string
  description = "Katalog z plikami VirtFusion: shared/ (windows-shared) i edition/ (windows-server-XXXX-standard)"
}

variable "edition" {
  type = string
  validation {
    condition     = contains(["2019", "2022", "2025"], var.edition)
    error_message = "Obsługiwane wydania: 2019, 2022, 2025."
  }
}

variable "evaluation" {
  type    = bool
  default = false
}

variable "iso" {
  type = string
}

variable "iso_checksum" {
  type    = string
  default = "none"
}

variable "virtio_iso" {
  type = string
}

variable "output_dir" {
  type = string
}

variable "vm_name" {
  type = string
}

variable "disk_size_mb" {
  type    = number
  default = 16000
}

variable "cpus" {
  type    = number
  default = 4
}

variable "memory_mb" {
  type    = number
  default = 4096
}

variable "password" {
  type      = string
  sensitive = true
}

variable "vnc_port" {
  type    = number
  default = 5990
}

variable "kms_key" {
  type        = string
  default     = ""
  description = "Klucz KMS klienta (GVLK) — przy konwersji wersji ewaluacyjnej 2025 na Standard"
}

locals {
  # Kroki przed czyszczeniem systemu — jak w windows-server.pkr.hcl VirtFusion.
  prepare = concat(
    var.edition == "2025" ? ["diskpart /s A:\\diskpart.txt"] : [],
    var.edition == "2025" && var.evaluation && var.kms_key != "" ? [
      "slmgr //b /upk",
      "slmgr //b /cpky",
      "slui //b /ipk ${var.kms_key}",
      "DISM /Online /Set-Edition:ServerStandard /ProductKey:${var.kms_key} /AcceptEula /NoRestart",
    ] : [],
    var.edition == "2019" ? ["dism /online /Remove-Capability /CapabilityName:Browser.InternetExplorer~~~~0.0.11.0 /NoRestart"] : [],
    ["echo VirtHub: przygotowanie"],
  )
  after_restart = concat(
    var.edition == "2019" ? ["powershell.exe a:\\edge.ps1"] : [],
    ["reg add \"HKEY_LOCAL_MACHINE\\SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsUpdate\\AU\" /v AUOptions /t REG_DWORD /d 7 /f"],
  )
}

source "qemu" "windows" {
  vm_name          = var.vm_name
  output_directory = var.output_dir
  iso_url          = var.iso
  iso_checksum     = var.iso_checksum
  disk_size        = "${var.disk_size_mb}M"
  format           = "qcow2"
  accelerator      = "kvm"
  headless         = true
  disk_cache       = "writeback"

  # Podgląd budowy tylko przez tunel SSH do węzła.
  vnc_bind_address = "127.0.0.1"
  vnc_port_min     = var.vnc_port
  vnc_port_max     = var.vnc_port + 9

  communicator   = "winrm"
  winrm_username = "Administrator"
  winrm_password = var.password
  winrm_port     = 5986
  winrm_use_ssl  = true
  winrm_insecure = true
  winrm_timeout  = "12h"
  host_port_min  = 20000
  host_port_max  = 29999

  floppy_files = [
    "${var.files_dir}/shared/scripts/*",
    "${var.files_dir}/edition/*",
    "${var.files_dir}/shared/patches/cloudinit/windows.py",
  ]

  # Własne -netdev: przekierowanie WinRM tylko na loopbacku (domyślne
  # przekierowanie Packera słucha na wszystkich adresach węzła).
  qemuargs = [
    ["-m", "${var.memory_mb}M"],
    ["-smp", "${var.cpus}"],
    ["-cpu", "host"],
    ["-netdev", "user,id=vhnet,hostfwd=tcp:127.0.0.1:{{ .SSHHostPort }}-:5986"],
    ["-device", "virtio-net,netdev=vhnet"],
    ["-drive", "file=${var.iso},media=cdrom,index=2"],
    ["-drive", "file=${var.virtio_iso},media=cdrom,index=3"],
    ["-drive", "file=${var.output_dir}/${var.vm_name},if=virtio,cache=writeback,discard=unmap,format=qcow2,index=1"],
  ]

  shutdown_command = "a:/sysprep.bat"
  shutdown_timeout = "30m"
}

build {
  sources = ["source.qemu.windows"]

  provisioner "windows-shell" {
    inline           = local.prepare
    valid_exit_codes = [0, 3010]
  }

  provisioner "powershell" {
    scripts = ["${var.files_dir}/shared/scripts/debloat-windows.ps1"]
  }

  provisioner "windows-restart" {
    restart_timeout = "15m"
  }

  provisioner "windows-shell" {
    inline           = local.after_restart
    valid_exit_codes = [0, 3010]
  }

  # set-winrm-automatic + compact: instalacja cloudbase-init i jego konfiguracji.
  provisioner "windows-shell" {
    execute_command = "{{ .Vars }} cmd /c \"{{ .Path }}\""
    scripts = [
      "${var.files_dir}/shared/scripts/set-winrm-automatic.bat",
      "${var.files_dir}/shared/scripts/compact.bat",
    ]
  }

  provisioner "powershell" {
    scripts = ["${var.files_dir}/shared/scripts/cleanup.ps1"]
  }
}
