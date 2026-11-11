<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue type names, sheet-label menu strings and the suspended-cycle
 * validation/title keys must be translated in en.json and pl.json. In en.json
 * every value equals its key (canonical name); pl.json must carry a Polish
 * translation. A missing or untranslated key silently falls back to English in
 * the UI.
 */
class IssueTypeTranslationTest extends TestCase
{
    /**
     * The 23 canonical issue type names.
     *
     * @return array<int, string>
     */
    private const ISSUE_TYPES = [
        'Material Defect',
        'Material Shortage',
        'Tool / Equipment Failure',
        'Quality Issue',
        'Process Clarification Required',
        'Safety Concern',
        'Measurement / Dimension Error',
        'Operator Assistance Required',
        'Inbound Quality — Inspection Failed',
        'In-process Quality — Control Failed',
        'Other Issue',
        'Bad CNC Program',
        'Board Moisture / Swollen Edge',
        'Decor Batch Mismatch',
        'Edge Tape Lifting / Open Joint',
        'Hole Pattern Out of Position',
        'Panel Out of Size / Out of Square',
        'Fittings Pack Short or Wrong',
        'Board Face Damaged or Scratched',
        'Saw Chipping on Face',
        'Gloss Front Orange Peel or Pinhole',
        'Carton Crushed or Split',
        'Machine Cycle Suspended',
    ];

    /**
     * SheetLabelsMenu strings.
     *
     * @return array<int, string>
     */
    private const SHEET_LABELS = [
        'Sheet labels',
        'AlphaCAM labels are not configured.',
        'AlphaCAM labels service returned an error.',
        'Could not load sheet labels.',
        'No labels for this sheet.',
        'Sheet labels: :sheet',
        'Order :no',
    ];

    /**
     * Suspended machine cycle title and validation messages.
     *
     * @return array<int, string>
     */
    private const SUSPENDED_CYCLE = [
        'Suspended machine cycle',
        'Describe what is on the machine, which sheet, and since when.',
        'Describe what is on the machine, which sheet, and since when (at least 10 characters).',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function keys(): array
    {
        $cases = [];

        foreach (array_merge(self::ISSUE_TYPES, self::SHEET_LABELS, self::SUSPENDED_CYCLE) as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private function lang(string $locale): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__, 2)."/lang/{$locale}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    #[DataProvider('keys')]
    public function test_key_is_translated_in_english_and_polish(string $key): void
    {
        $en = $this->lang('en');
        $pl = $this->lang('pl');

        $this->assertArrayHasKey($key, $en);
        $this->assertSame($key, $en[$key], 'en.json value must equal its key');

        $this->assertArrayHasKey($key, $pl);
        $this->assertIsString($pl[$key]);
        $this->assertNotSame('', trim($pl[$key]));
        $this->assertNotSame($key, $pl[$key], 'pl.json must carry a Polish translation');
    }
}
