<?php

declare(strict_types=1);

use Politiks\App\Insight\InsightStore;
use Politiks\App\Insight\InsightException;
use Politiks\App\Insight\WizardStore;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';
require __DIR__ . '/TestReferenceFixture.php';

$email = 'wizard.integration@example.test';

try {
    $pdo = MariaDb::connect(Environment::load(dirname(__DIR__, 2) . '/.env.test'));
    ensureWizardReferenceFixture($pdo);
    $old = $pdo->prepare('SELECT id FROM app_user WHERE email=?');
    $old->execute([$email]);
    $oldId = $old->fetchColumn();
    if ($oldId !== false) {
        $pdo->prepare('DELETE FROM insight WHERE owner_user_id=?')->execute([$oldId]);
        $pdo->prepare('DELETE FROM app_user WHERE id=?')->execute([$oldId]);
    }
    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES ('wizard-integration-subject', ?, 'Wizard Integration', 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertUser->execute([$email]);
    $ownerId = (int) $pdo->lastInsertId();

    $candidate = $pdo->query(
        "SELECT event.publication_id, country.source_id country_id, legislature.source_id legislature_id,
                event.chamber_source_id chamber_id, party_membership.party_source_id party_id,
                choice.person_source_id person_id, event.source_id event_id,
                LEAST(DATE(event.occurred_at), COALESCE(party_membership.date_from, DATE(event.occurred_at))) scope_from,
                GREATEST(DATE(event.occurred_at), COALESCE(party_membership.date_to, DATE(event.occurred_at))) scope_to,
                choice.normalized_choice
         FROM ref_voting_choice choice
         JOIN ref_voting_event event ON event.publication_id=choice.publication_id
           AND event.source_id=choice.voting_event_source_id AND event.chamber_source_id IS NOT NULL
         JOIN ref_person_party_membership party_membership ON party_membership.publication_id=choice.publication_id
           AND party_membership.person_source_id=choice.person_source_id
         JOIN ref_person_mandate mandate ON mandate.publication_id=choice.publication_id
           AND mandate.person_source_id=choice.person_source_id
           AND mandate.chamber_source_id=event.chamber_source_id
           AND COALESCE(mandate.date_from, '0001-01-01')<=DATE(event.occurred_at)
           AND COALESCE(mandate.date_to, '9999-12-31')>=DATE(event.occurred_at)
         JOIN ref_chamber chamber ON chamber.publication_id=event.publication_id
           AND chamber.source_id=event.chamber_source_id
         JOIN ref_legislature legislature ON legislature.publication_id=chamber.publication_id
           AND legislature.source_id=chamber.legislature_source_id
         JOIN ref_country country ON country.publication_id=legislature.publication_id
           AND country.source_id=legislature.country_source_id
         WHERE choice.normalized_choice IN ('yes','no','abstain','other')
         ORDER BY event.occurred_at DESC LIMIT 1"
    )->fetch();
    if ($candidate === false) {
        $counts = $pdo->query(
            'SELECT
               (SELECT COUNT(*) FROM ref_voting_choice choice_row JOIN ref_person_party_membership membership ON membership.publication_id=choice_row.publication_id AND membership.person_source_id=choice_row.person_source_id) party_links,
               (SELECT COUNT(*) FROM ref_voting_choice choice_row JOIN ref_person_mandate mandate ON mandate.publication_id=choice_row.publication_id AND mandate.person_source_id=choice_row.person_source_id) mandate_links,
               (SELECT COUNT(*) FROM ref_voting_choice choice_row JOIN ref_voting_event event_row ON event_row.publication_id=choice_row.publication_id AND event_row.source_id=choice_row.voting_event_source_id JOIN ref_person_mandate mandate ON mandate.publication_id=choice_row.publication_id AND mandate.person_source_id=choice_row.person_source_id AND mandate.chamber_source_id=event_row.chamber_source_id) chamber_links'
        )->fetch();
        throw new RuntimeException(sprintf(
            'Fixture has no wizard candidate (party links %d, mandate links %d, chamber links %d).',
            $counts['party_links'],
            $counts['mandate_links'],
            $counts['chamber_links'],
        ));
    }

    $insights = new InsightStore(static fn (): PDO => $pdo, 'https://politiks.example.test');
    $wizard = new WizardStore(static fn (): PDO => $pdo);
    $draft = $insights->createDraft($ownerId);
    $scope = [
        'country_id' => (int) $candidate['country_id'],
        'legislature_id' => (int) $candidate['legislature_id'],
        'chamber_id' => (int) $candidate['chamber_id'],
        'party_id' => (int) $candidate['party_id'],
        'period_from' => $candidate['scope_from'],
        'period_to' => $candidate['scope_to'],
    ];
    $wizard->saveScope($ownerId, $draft['public_id'], $scope);
    $members = $wizard->eligibleMembers($ownerId, $draft['public_id']);
    if (!in_array((int) $candidate['person_id'], array_column($members, 'id'), true)) {
        throw new RuntimeException('Date-valid candidate was absent from eligible members.');
    }
    $allMemberIds = array_column($members, 'id');
    $allVotes = $wizard->votes($ownerId, $draft['public_id'], $allMemberIds, '');
    $byId = [];
    foreach ($allVotes['items'] as $item) {
        $byId[$item['id']] = $item;
    }
    foreach ([910301 => 'yes', 910302 => 'no', 910303 => 'split', 910304 => 'non_directional', 910305 => 'no'] as $eventId => $direction) {
        if (($byId[$eventId]['direction'] ?? null) !== $direction) {
            throw new RuntimeException("Fixture event $eventId did not calculate as $direction.");
        }
    }
    $withoutCarla = array_values(array_diff($allMemberIds, [910103]));
    $splitVotes = $wizard->votes($ownerId, $draft['public_id'], $withoutCarla, 'NR:TEST-5');
    if (($splitVotes['items'][0]['direction'] ?? null) !== 'split') {
        throw new RuntimeException('Removing the fixture outlier did not move TEST-5 to Split.');
    }
    $annaAndDavid = [910101, 910104];
    $yesVotes = $wizard->votes($ownerId, $draft['public_id'], $annaAndDavid, 'NR:TEST-5');
    if (($yesVotes['items'][0]['direction'] ?? null) !== 'yes') {
        throw new RuntimeException('The reduced fixture cohort did not move TEST-5 to Yes.');
    }
    $exact = $wizard->votes($ownerId, $draft['public_id'], $allMemberIds, 'NR:TEST-3');
    if (count($exact['items']) !== 1 || $exact['items'][0]['id'] !== 910303 || $exact['items'][0]['match_context'] === null) {
        throw new RuntimeException('Exact identifier lookup or highlighted match context failed.');
    }
    if ($byId[910301]['official_topics'] !== ['Wirtschaft']
        || $byId[910301]['reviewed_classifications'] !== ['Wirtschaftspolitik']) {
        throw new RuntimeException('Official and reviewed classifications were not kept distinct.');
    }
    $wizard->saveMembers($ownerId, $draft['public_id'], [(int) $candidate['person_id']]);
    $votes = $wizard->votes($ownerId, $draft['public_id'], [(int) $candidate['person_id']], '');
    $voteIds = array_column($votes['items'], 'id');
    if (!in_array((int) $candidate['event_id'], $voteIds, true)) {
        throw new RuntimeException('Known candidate vote was absent from cohort results.');
    }
    $known = $votes['items'][array_search((int) $candidate['event_id'], $voteIds, true)];
    $expectedDirection = $candidate['normalized_choice'] === 'yes'
        ? 'yes'
        : ($candidate['normalized_choice'] === 'no' ? 'no' : 'non_directional');
    if ($known['direction'] !== $expectedDirection) {
        throw new RuntimeException('Single-member cohort direction was calculated incorrectly.');
    }
    $wizard->saveEvidence($ownerId, $draft['public_id'], [(int) $candidate['event_id']]);
    $published = $insights->update($ownerId, $draft['public_id'], [
        'title' => 'Wizard integration',
        'claim_text' => 'Die Testaussage besitzt vollständige parlamentarische Evidenz.',
        'visibility' => 'public',
    ]);
    if ($published['visibility'] !== 'public') {
        throw new RuntimeException('Complete wizard insight could not be published.');
    }
    $state = $wizard->state($ownerId, $draft['public_id']);
    if ($state['insight']['evidence_ids'] !== [(int) $candidate['event_id']]) {
        throw new RuntimeException('Evidence ordering did not persist.');
    }
    $snapshot = $pdo->prepare(
        'SELECT evidence.reference_publication_id, evidence.voting_event_source_id
         FROM insight_vote_evidence evidence
         JOIN insight ON insight.id=evidence.insight_id WHERE insight.public_id=?'
    );
    $snapshot->execute([$draft['public_id']]);
    $snapshotRow = $snapshot->fetch();
    if ((int) $snapshotRow['reference_publication_id'] !== (int) $candidate['publication_id']
        || (int) $snapshotRow['voting_event_source_id'] !== (int) $candidate['event_id']) {
        throw new RuntimeException('Evidence did not retain its publication and source identifiers.');
    }

    $blockedDraft = $insights->createDraft($ownerId);
    $wizard->saveScope($ownerId, $blockedDraft['public_id'], $scope);
    $wizard->saveMembers($ownerId, $blockedDraft['public_id'], [910103, 910104]);
    $wizard->saveEvidence($ownerId, $blockedDraft['public_id'], [910303]);
    $retained = $wizard->votes($ownerId, $blockedDraft['public_id'], [910103, 910104], 'unrelated search');
    if (count($retained['items']) !== 1 || !$retained['items'][0]['participation_warning']) {
        throw new RuntimeException('Selected no-participation evidence was not retained with a warning.');
    }
    try {
        $insights->update($ownerId, $blockedDraft['public_id'], [
            'title' => 'Blocked publication',
            'claim_text' => 'Diese Aussage darf ohne Teilnahme nicht publiziert werden.',
            'visibility' => 'public',
        ]);
        throw new RuntimeException('No-participation evidence was incorrectly accepted for publication.');
    } catch (InsightException $error) {
        if ($error->errorCode !== 'EVIDENCE_WITHOUT_PARTICIPATION') {
            throw $error;
        }
    }

    $abstentionDraft = $insights->createDraft($ownerId);
    $wizard->saveScope($ownerId, $abstentionDraft['public_id'], $scope);
    $wizard->saveMembers($ownerId, $abstentionDraft['public_id'], $allMemberIds);
    $wizard->saveEvidence($ownerId, $abstentionDraft['public_id'], [910304]);
    $abstentionPublication = $insights->update($ownerId, $abstentionDraft['public_id'], [
        'title' => 'Abstention publication',
        'claim_text' => 'Enthaltungen gelten als erfasste, aber nicht gerichtete Teilnahme.',
        'visibility' => 'public',
    ]);
    if ($abstentionPublication['visibility'] !== 'public') {
        throw new RuntimeException('Abstention-only evidence was incorrectly blocked.');
    }

    $scopeChangeDraft = $insights->createDraft($ownerId);
    $wizard->saveScope($ownerId, $scopeChangeDraft['public_id'], $scope);
    $wizard->saveMembers($ownerId, $scopeChangeDraft['public_id'], [910101]);
    $wizard->saveEvidence($ownerId, $scopeChangeDraft['public_id'], [910301]);
    $changedScope = $scope;
    $changedScope['period_from'] = '2025-02-01';
    $wizard->saveScope($ownerId, $scopeChangeDraft['public_id'], $changedScope);
    $clearedState = $wizard->state($ownerId, $scopeChangeDraft['public_id']);
    if ($clearedState['insight']['members'] !== [] || $clearedState['insight']['evidence_ids'] !== []) {
        throw new RuntimeException('Changing the parliamentary scope retained stale selections.');
    }

    echo json_encode([
        'historical_member_scope_valid' => true,
        'cohort_direction_valid' => true,
        'exact_search_and_filters_valid' => true,
        'evidence_snapshot_persisted' => true,
        'participation_rules_valid' => true,
        'scope_change_resets_selections' => true,
        'publication_validation_valid' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Wizard integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($pdo, $ownerId)) {
        try {
            $pdo->prepare('DELETE FROM insight WHERE owner_user_id=?')->execute([$ownerId]);
            $pdo->prepare('DELETE FROM app_user WHERE id=?')->execute([$ownerId]);
        } catch (Throwable) {
        }
    }
}
