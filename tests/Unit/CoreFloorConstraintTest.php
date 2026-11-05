<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);

/**
 * The branch the README names and the branch the constraint allows.
 *
 * The README tells a reader which core the plugin's own install resolves
 * against, and the constraint is what resolves. They drifted once: the
 * constraint named one branch and the README another, so the README
 * described a branch no install would ever use.
 */
it('names in the README the core branch the constraint allows', function () use ($pluginRoot) {
    $manifest = json_decode((string) file_get_contents($pluginRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $constraint = (string) $manifest['require']['spora-ai/spora-core'];

    preg_match_all('/\bdev-[a-z0-9._\/-]+\b/', $constraint, $constraintBranches);
    preg_match_all('/\bdev-[a-z0-9._\/-]+\b/', (string) file_get_contents($pluginRoot . '/README.md'), $readmeBranches);

    $allowed = array_values(array_unique($constraintBranches[0]));
    $named = array_values(array_unique($readmeBranches[0]));

    sort($allowed);
    sort($named);

    expect($named)->toBe($allowed);
});
