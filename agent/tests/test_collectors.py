import unittest

from mirvmon_agent.collectors import ServiceChangeTracker


class ServiceChangeTrackerTest(unittest.TestCase):
    def test_only_changed_service_states_are_emitted_after_first_sample(self):
        tracker = ServiceChangeTracker()
        initial = [
            {"name": "a.service", "status": "running"},
            {"name": "b.service", "status": "stopped"},
        ]
        self.assertEqual(initial, tracker.changed(initial))
        self.assertEqual([], tracker.changed(initial))

        changed = [
            {"name": "a.service", "status": "stopped"},
            {"name": "b.service", "status": "stopped"},
        ]
        self.assertEqual([changed[0]], tracker.changed(changed))


if __name__ == "__main__":
    unittest.main()
