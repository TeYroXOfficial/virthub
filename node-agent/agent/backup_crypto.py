"""Szyfrowanie kopii przed wysyłką na cel (AES-256-GCM w ramkach).

Format: nagłówek ``VHENC1\\n``, potem ramki ``[4 B długość][12 B nonce][szyfrogram+tag]``.
Każda ramka ma osobny losowy nonce, a dane uwierzytelniające (AAD) zawierają
numer ramki i znacznik ostatniej — zamiana kolejności, usunięcie albo ucięcie
ramek kończy się błędem odszyfrowania, a nie cicho uszkodzonym dyskiem.
Klucz (32 B, base64) nadaje panel i nie zapisuje się go na węźle.
"""

from __future__ import annotations

import base64
import os
import struct
from pathlib import Path

MAGIC = b"VHENC1\n"
FRAME = 4 * 1024 * 1024


class BackupCryptoError(RuntimeError):
    pass


def _key(encoded: str) -> bytes:
    try:
        key = base64.b64decode(encoded, validate=True)
    except ValueError as exc:
        raise BackupCryptoError("Nieprawidłowy klucz szyfrowania kopii.") from exc
    if len(key) != 32:
        raise BackupCryptoError("Klucz szyfrowania kopii musi mieć 32 bajty (AES-256).")
    return key


def _aad(index: int, last: bool) -> bytes:
    return MAGIC + struct.pack(">Q?", index, last)


def is_encrypted(path: Path) -> bool:
    with path.open("rb") as fh:
        return fh.read(len(MAGIC)) == MAGIC


def encrypt_file(source: Path, target: Path, encoded_key: str) -> None:
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    aes = AESGCM(_key(encoded_key))
    with source.open("rb") as src, target.open("wb") as out:
        out.write(MAGIC)
        index = 0
        chunk = src.read(FRAME)
        while True:
            following = src.read(FRAME)
            last = not following
            nonce = os.urandom(12)
            sealed = aes.encrypt(nonce, chunk, _aad(index, last))
            out.write(struct.pack(">I", len(sealed)) + nonce + sealed)
            if last:
                break
            chunk, index = following, index + 1


def decrypt_file(source: Path, target: Path, encoded_key: str) -> None:
    from cryptography.exceptions import InvalidTag
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    aes = AESGCM(_key(encoded_key))
    with source.open("rb") as src, target.open("wb") as out:
        if src.read(len(MAGIC)) != MAGIC:
            raise BackupCryptoError("Kopia nie jest zaszyfrowana kluczem VirtHub (inny format pliku).")
        index = 0
        header = src.read(4)
        while header:
            if len(header) != 4:
                raise BackupCryptoError("Kopia jest ucięta.")
            (length,) = struct.unpack(">I", header)
            nonce, sealed = src.read(12), src.read(length)
            if len(nonce) != 12 or len(sealed) != length:
                raise BackupCryptoError("Kopia jest ucięta.")
            header = src.read(4)
            try:
                out.write(aes.decrypt(nonce, sealed, _aad(index, not header)))
            except InvalidTag as exc:
                raise BackupCryptoError("Nie udało się odszyfrować kopii — zły klucz albo uszkodzony plik.") from exc
            index += 1
        if index == 0:
            raise BackupCryptoError("Kopia jest pusta.")
