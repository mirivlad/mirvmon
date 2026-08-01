import unittest

from mirvmon_agent.collectors import ServiceChangeTracker, SystemCollector


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


class SystemCollectorServiceTest(unittest.TestCase):
    def test_sysv_service_listing_preserves_service_monitoring(self):
        collector = SystemCollector()
        collector._command = lambda arguments: " [ + ]  cron\n [ - ]  nginx\n [ ? ]  unknown\n"

        services = collector._sysv_services()

        self.assertEqual(
            [
                {"name": "cron", "status": "running", "load_state": "sysv", "active_state": "active", "sub_state": "unknown"},
                {"name": "nginx", "status": "stopped", "load_state": "sysv", "active_state": "inactive", "sub_state": "unknown"},
                {"name": "unknown", "status": "unknown", "load_state": "sysv", "active_state": "unknown", "sub_state": "unknown"},
            ],
            services,
        )


if __name__ == "__main__":
    unittest.main()
