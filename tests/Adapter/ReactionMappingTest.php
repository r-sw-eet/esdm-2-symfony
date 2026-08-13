<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Adapter;

use Esdm\Generator\Adapter\SymfonyEventSourcingDb\SymfonyEventSourcingDbAdapter;
use Esdm\Generator\Adapter\SymfonyPatchlevelPostgres\SymfonyPatchlevelPostgresAdapter;
use Esdm\Generator\Model\DocumentLoader;
use Esdm\Generator\Model\ModelFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extension proposal 0005: a declared mapping must reproduce the documented default exactly, and
 * must actually take effect when it differs - the first check alone would also pass if the
 * annotation were silently ignored.
 */
final class ReactionMappingTest extends TestCase
{
    private const MODEL_DIR = __DIR__ . '/../../examples/manufacturing/model';
    /** Unique to the reaction document: only a policy carries deliveryGuarantee. */
    private const POLICY_ANCHOR = "scope:\n  domain: manufacturing\ndeliveryGuarantee:";
    private const DEFAULT_MAPPING = '{ requestId: id, customerName: customerName, product: product, quantity: quantity }';
    private const SWAPPED_MAPPING = '{ requestId: id, customerName: product, product: customerName, quantity: quantity }';

    /** @return iterable<string, array{object}> */
    public static function adapters(): iterable
    {
        yield 'patchlevel' => [new SymfonyPatchlevelPostgresAdapter()];
        yield 'eventsourcingdb' => [new SymfonyEventSourcingDbAdapter()];
    }

    #[DataProvider('adapters')]
    public function testAMappingThatStatesTheDefaultChangesNothing(object $adapter): void
    {
        self::assertSame(
            $this->generate($adapter, null),
            $this->generate($adapter, self::DEFAULT_MAPPING),
        );
    }

    #[DataProvider('adapters')]
    public function testADifferentMappingReachesTheEmittedReaction(object $adapter): void
    {
        $plain = $this->generate($adapter, null);
        $swapped = $this->generate($adapter, self::SWAPPED_MAPPING);

        $policies = array_filter(array_keys($plain), static fn (string $p): bool => str_contains($p, '/Policy/'));
        self::assertNotEmpty($policies, 'no policy file was emitted');

        $changed = false;
        foreach ($policies as $path) {
            $changed = $changed || $swapped[$path] !== $plain[$path];
        }
        self::assertTrue($changed, 'the swapped mapping did not reach any emitted policy');
    }

    public function testTheExampleModelStillCarriesTheReactionThisTestRewrites(): void
    {
        $model = (new ModelFactory())->create((new DocumentLoader())->loadDirectory(self::MODEL_DIR));

        self::assertSame(['draft-quote-on-request'], array_map(static fn ($p): string => $p->name, $model->policies));
    }

    /** @return array<string, string> */
    private function generate(object $adapter, ?string $mapping): array
    {
        $work = sys_get_temp_dir() . '/esdm-mapping-' . bin2hex(random_bytes(6));
        mkdir($work, 0o777, true);

        try {
            foreach (glob(self::MODEL_DIR . '/*') ?: [] as $file) {
                $text = (string) file_get_contents($file);
                if (basename($file) === 'manufacturing.esdm.yaml') {
                    // Independent of whether the fixture itself declares a mapping: drop any, add ours.
                    $text = (string) preg_replace(
                        '/\nmetadata:\n  annotations:\n    esdm-extensions\.io\/mapping: "[^"]*"/',
                        '',
                        $text,
                    );
                    if ($mapping !== null) {
                        $text = str_replace(
                            self::POLICY_ANCHOR,
                            "metadata:\n  annotations:\n" . '    esdm-extensions.io/mapping: "' . $mapping . "\"\n"
                                . self::POLICY_ANCHOR,
                            $text,
                        );
                    }
                }
                file_put_contents($work . '/' . basename($file), $text);
            }

            $model = (new ModelFactory())->create((new DocumentLoader())->loadDirectory($work));

            return $adapter->generate($model, ['appName' => 'manufacturing', 'namespace' => 'App'])->files();
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($work);
        }
    }
}
