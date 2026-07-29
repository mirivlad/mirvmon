import unittest

from mirvmon_agent.redaction import redact_command


class RedactionTest(unittest.TestCase):
    def test_secret_options_and_url_credentials_are_redacted(self):
        command = (
            "backup --password hunter2 --token=abc "
            "https://alice:secret@example.test/path?api_key=value"
        )

        redacted = redact_command(command)

        self.assertNotIn("hunter2", redacted)
        self.assertNotIn("abc", redacted)
        self.assertNotIn("alice", redacted)
        self.assertNotIn("secret", redacted)
        self.assertNotIn("value", redacted)
        self.assertIn("[REDACTED]", redacted)

    def test_normal_arguments_are_preserved(self):
        self.assertEqual(
            "postgres --config /etc/postgresql.conf",
            redact_command("postgres --config /etc/postgresql.conf"),
        )


if __name__ == "__main__":
    unittest.main()
