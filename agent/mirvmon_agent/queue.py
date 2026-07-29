from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any


class PersistentQueue:
    def __init__(self, path: Path, max_items: int = 1000) -> None:
        if max_items < 1 or max_items > 10000:
            raise ValueError("Queue size is out of range.")
        self.path = path
        self.max_items = max_items
        self._items = self._load()

    def __len__(self) -> int:
        return len(self._items)

    def append(self, item: dict[str, Any]) -> None:
        self._items.append(item)
        if len(self._items) > self.max_items:
            self._items = self._items[-self.max_items :]
        self._persist()

    def peek(self) -> dict[str, Any] | None:
        return self._items[0] if self._items else None

    def pop(self) -> dict[str, Any] | None:
        if not self._items:
            return None
        item = self._items.pop(0)
        self._persist()
        return item

    def _load(self) -> list[dict[str, Any]]:
        if not self.path.exists():
            return []
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(payload, list) or any(
                not isinstance(item, dict) for item in payload
            ):
                raise ValueError("Invalid queue data.")
            os.chmod(self.path, 0o600)
            return payload[-self.max_items :]
        except (OSError, ValueError, json.JSONDecodeError):
            corrupt = Path(str(self.path) + ".corrupt")
            try:
                os.replace(self.path, corrupt)
                os.chmod(corrupt, 0o600)
            except OSError:
                pass
            return []

    def _persist(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        descriptor, temporary_name = tempfile.mkstemp(
            prefix=".queue-",
            suffix=".tmp",
            dir=self.path.parent,
        )
        temporary_path = Path(temporary_name)
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
                json.dump(
                    self._items,
                    stream,
                    ensure_ascii=False,
                    separators=(",", ":"),
                )
                stream.flush()
                os.fsync(stream.fileno())
            os.chmod(temporary_path, 0o600)
            os.replace(temporary_path, self.path)
            os.chmod(self.path, 0o600)
        finally:
            if temporary_path.exists():
                temporary_path.unlink()
