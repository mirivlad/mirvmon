import json
import os
import tempfile
import unittest
from pathlib import Path

from mirvmon_agent.queue import PersistentQueue


class PersistentQueueTest(unittest.TestCase):
    def test_queue_survives_restart_and_is_owner_only(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "queue.json"
            queue = PersistentQueue(path, max_items=3)
            queue.append({"sample_id": "one"})
            queue.append({"sample_id": "two"})

            reloaded = PersistentQueue(path, max_items=3)

            self.assertEqual(2, len(reloaded))
            self.assertEqual("one", reloaded.peek()["sample_id"])
            self.assertEqual(0o600, os.stat(path).st_mode & 0o777)
            reloaded.pop()
            self.assertEqual("two", PersistentQueue(path).peek()["sample_id"])

    def test_queue_drops_oldest_item_when_bounded_size_is_reached(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "queue.json"
            queue = PersistentQueue(path, max_items=2)
            queue.append({"sample_id": "one"})
            queue.append({"sample_id": "two"})
            queue.append({"sample_id": "three"})

            self.assertEqual(
                ["two", "three"],
                [item["sample_id"] for item in json.loads(path.read_text())],
            )

    def test_corrupt_queue_is_quarantined_instead_of_crashing(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "queue.json"
            path.write_text("{")

            queue = PersistentQueue(path)

            self.assertEqual(0, len(queue))
            self.assertTrue((Path(str(path) + ".corrupt")).exists())


if __name__ == "__main__":
    unittest.main()
