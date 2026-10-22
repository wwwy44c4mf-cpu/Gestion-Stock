import unittest
import xml.etree.ElementTree as ET
from check_coverage import evaluate


class ProofTest(unittest.TestCase):
    def test_missing_test_cannot_be_claimed_as_passed(self):
        report = evaluate(ET.Element('testsuites'))
        self.assertFalse(any(row['passed'] for row in report.values()))

    def test_skipped_or_failed_proof_is_not_coverage(self):
        for outcome in (None, 'skipped', 'failure', 'error'):
            root = ET.Element('testsuites')
            node = ET.SubElement(root, 'testcase', {'class': 'App\\Tests\\ConsumptionTest', 'name': 'testEmptyOptionsContextNavigationAndAnonymousRequests'})
            if outcome:
                ET.SubElement(node, outcome)
            self.assertEqual(outcome is None, evaluate(root)['REC-01']['passed'])


if __name__ == '__main__':
    unittest.main()
