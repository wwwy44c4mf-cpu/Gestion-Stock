import math
import unittest
from recette import measures, percentile


class MeasurementTest(unittest.TestCase):
    def test_p95_is_nearest_rank_and_not_average_or_maximum(self):
        self.assertEqual(95, percentile(list(range(1, 101))))
        self.assertEqual(95, percentile(list(reversed(range(1, 101)))))

    def test_slow_tail_fails_and_limit_is_strict(self):
        self.assertFalse(measures([10] * 94 + [3000] * 6, 3000)['passed'])
        self.assertTrue(measures([10] * 95 + [4000] * 5, 3000)['passed'])

    def test_missing_or_invalid_samples_cannot_pass(self):
        for samples in ([], [1] * 99, [1] * 99 + [math.nan], [1] * 99 + [-1]):
            with self.assertRaises(ValueError):
                measures(samples, 2000)


if __name__ == '__main__':
    unittest.main()
