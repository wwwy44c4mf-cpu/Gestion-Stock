"""Require successful executable proofs, not just test file names, for REC-01..18."""
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

PROOFS = {
    'REC-01': ('ConsumptionTest', 'testEmptyOptionsContextNavigationAndAnonymousRequests'),
    'REC-02': ('AccessControlTest', 'testMutationBoundariesAndBothTransferStores'),
    'REC-03': ('StockEntryTest', 'testApiCreditsFivePlusTenWithAuthorServerDateAndOneDurableEvent'),
    'REC-04': ('ConsumptionTest', 'testUserApiConsumesFourOfFifteenForSelfWithServerAttributionAndReceipt'),
    'REC-05': ('ConsumptionTest', 'testInsufficientStockInventoryAndRevokedAssignmentsLeaveStockUnchanged'),
    'REC-06': ('StockMovementConcurrencyTest', 'testLastUnitReplaysFirstCreditsAndOppositeTransfersAcrossProcesses'),
    'REC-07': ('StockTransferTest', 'testApiDebitsAndCreditsThreeWithOneCommonIdAndDurableEvent'),
    'REC-08': ('NotificationConcurrencyTest', 'testTwoDispatchersAndTwoWorkersDoNotDuplicateDelivery'),
    'REC-09': ('AlertLifecycleTest', 'testResolvedAlertsAndTransitionsAreImmutable'),
    'REC-10': ('InventoryValidationTest', 'testTenToEightClosesWithExactlyOneLinkedAdjustmentAndReleasesStore'),
    'REC-11': ('ConsumptionStatisticsTest', 'testStoreCategoryAndDateFiltersApplyToEveryAggregate'),
    'REC-12': ('ConsumptionStatisticsTest', 'testKnownDatasetTotalsScopesAndSeparateReturnDates'),
    'REC-13': ('NotificationTest', 'testSmtpFailureDoesNotRollBackMovementAndRetriesAreBoundedAndSanitized'),
    'REC-14': ('ConsumptionTest', 'testReplayKeepsTheOriginalAttributionAndDoesNotDebitAgain'),
    'REC-17': ('CatalogTest', 'testArchivedArticleKeepsAllHistoryAndCannotBeConsumedEvenByDirectSql'),
    'REC-18': ('UserAdministrationTest', 'testDeactivationImmediatelyRejectsSessionsAndNewLogins'),
}
EXTRA = {
    'returns': ('StockReturnConcurrencyTest', 'testReturnLimitsReplayAndCorrectionsSerializeAcrossProcesses'),
    'catalog': ('ProposalTest', 'testSubmissionLeavesCatalogueUnchangedAndOnlyAdministratorPublishes'),
    'inventory_freeze': ('ConsumptionTest', 'testInsufficientStockInventoryAndRevokedAssignmentsLeaveStockUnchanged'),
    'alert_thresholds': ('AlertLifecycleTest', 'testExactThresholdsCriticalPriorityAndEveryDirection'),
    'statistics_scope': ('AccessControlTest', 'testScopesApplyBeforeSumsCountsAndPagination'),
    'privacy': ('PrivacyRetentionTest', 'testAtomicErasureChainIntegrityActiveIdentityAndRepeatRun'),
}


def evaluate(root):
    cases = {(node.get('class', node.get('classname', '')).split('\\')[-1], node.get('name')): node for node in root.iter('testcase')}
    report = {}
    for code, proof in {**PROOFS, **EXTRA}.items():
        node = cases.get(proof)
        passed = node is not None and not any(child.tag in ('failure', 'error', 'skipped', 'warning') for child in node)
        report[code] = {'passed': passed, 'proof': '::'.join(proof)}
    return report


def main():
    directory = Path(sys.argv[1])
    mode = sys.argv[2]
    report = evaluate(ET.parse(directory / 'phpunit.xml').getroot())
    if not all(row['passed'] for row in report.values()):
        raise SystemExit('Required recipe proofs missing or unsuccessful: ' + ','.join(code for code, row in report.items() if not row['passed']))
    for code in ('REC-15', 'REC-16'):
        report[code] = {'passed': (directory / 'backup-recovery.passed').exists(), 'proof': 'bin/test-backup-recovery'}
    result = {'status': 'full_passed' if all(row['passed'] for row in report.values()) else 'core_passed_external_checks_required', 'criteria': report}
    (directory / 'coverage.json').write_text(json.dumps(result, indent=2) + '\n')
    if mode == 'full' and not all(row['passed'] for row in report.values()):
        raise SystemExit('Full recipe requires REC-15 and REC-16')
    print(result['status'])


if __name__ == '__main__':
    main()
