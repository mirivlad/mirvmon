import unittest
from pathlib import Path

from mirvmon_agent.config import AgentConfig


class AgentConfigTest(unittest.TestCase):
    def test_configuration_is_immutable_after_validation(self):
        config = AgentConfig(
            api_url="https://monitor.example/api/v1/metrics",
            config_url="https://monitor.example/api/v1/agent/config",
            token="a" * 64,
            queue_path=Path("/tmp/mirvmon-queue.json"),
        )

        with self.assertRaises(AttributeError):
            config.interval_seconds = 10


if __name__ == "__main__":
    unittest.main()
