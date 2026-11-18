import json
import unittest

from inventory import collect


class InventoryTest(unittest.TestCase):
    def test_uses_only_project_filtered_read_commands(self):
        commands = []
        record = dict(Name="n8n", Service="n8n", State="running", Status="healthy",
                      Image="n8n", Ports="5678/tcp", Mounts="n8n_data", Networks="private")
        def query(command):
            commands.append(command)
            return [json.dumps(record | {"Env": "private"})] if command[0] == "ps" else []

        report = collect("n8n-staging", query)
        self.assertEqual(report["containers"], [record])
        self.assertEqual([command[0] for command in commands], ["ps", "volume", "network"])
        for command in commands:
            self.assertIn("label=com.docker.compose.project=n8n-staging", command)

    def test_refuses_path_or_argument_as_project(self):
        for project in ("--all", "../n8n", "n8n;id"):
            with self.subTest(project=project), self.assertRaises(ValueError):
                collect(project, lambda _: self.fail("invoked Docker"))


if __name__ == "__main__":
    unittest.main()
