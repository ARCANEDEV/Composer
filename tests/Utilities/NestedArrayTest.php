<?php namespace Arcanedev\Composer\Tests\Utilities;

use Arcanedev\Composer\Tests\TestCase;
use Arcanedev\Composer\Utilities\NestedArray;

/**
 * Class     NestedArrayTest
 *
 * @package  Arcanedev\Composer\Tests\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class NestedArrayTest extends TestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Test Functions
     | ------------------------------------------------------------------------------------------------
     */
    /** @test */
    public function it_can_merge_deep_array()
    {
        $arrayOne = [
            'fragment'   => 'x',
            'attributes' => ['title' => 'X', 'class' => ['a', 'b']],
            'language'   => 'en',
        ];

        $arrayTwo = [
            'fragment'   => 'y',
            'attributes' => ['title' => 'Y', 'class' => ['c', 'd']],
            'absolute'   => true,
        ];

        $expected = [
            'fragment'   => 'y',
            'attributes' => [
                'title'  => 'Y', 'class' => ['a', 'b', 'c', 'd']
            ],
            'language'   => 'en',
            'absolute'   => true,
        ];

        $this->assertSame(
            $expected,
            NestedArray::mergeDeepArray([$arrayOne, $arrayTwo]),
            'NestedArray::mergeDeepArray() returned a properly merged array.'
        );

        // Test wrapper function, NestedArray::mergeDeep().
        $this->assertSame(
            $expected,
            NestedArray::mergeDeep($arrayOne, $arrayTwo),
            'NestedArray::mergeDeep() returned a properly merged array.'
        );
    }

    /** @test */
    public function it_can_merge_implicit_keys()
    {
        $arrayOne = [
            'subkey' => ['X', 'Y'],
        ];
        $arrayTwo = [
            'subkey' => ['X'],
        ];

        $this->assertSame(
            [
                'subkey' => ['X', 'Y', 'X'], // Drupal core behavior.
            ],
            NestedArray::mergeDeepArray([$arrayOne, $arrayTwo]),
            'mergeDeepArray::mergeDeepArray creates new numeric keys in the implicit sequence.'
        );
    }

    /** @test */
    public function it_can_merge_explicit_keys()
    {
        $arrayOne = [
            'subkey' => [
                0 => 'A',
                1 => 'B',
            ],
        ];

        $arrayTwo = [
            'subkey' => [
                0 => 'C',
                1 => 'D',
            ],
        ];

        $this->assertSame(
            [
                // Drupal core behavior.
                'subkey' => [
                    0 => 'A',
                    1 => 'B',
                    2 => 'C',
                    3 => 'D',
                ],
            ],
            NestedArray::mergeDeepArray([$arrayOne, $arrayTwo]),
            'NestedArray::mergeDeepArray creates new numeric keys in the explicit sequence.'
        );
    }

    /** @test */
    public function it_can_merge_out_of_sequence_keys()
    {
        $arrayOne = [
            'subkey' => [
                10 => 'A',
                30 => 'B',
            ],
        ];

        $arrayTwo = [
            'subkey' => [
                20 => 'C',
                0 => 'D',
            ],
        ];

        $this->assertSame(
            [
                // Drupal core behavior.
                'subkey' => [
                    0 => 'A',
                    1 => 'B',
                    2 => 'C',
                    3 => 'D',
                ],
            ],
            NestedArray::mergeDeepArray([$arrayOne, $arrayTwo]),
            'NestedArray::mergeDeepArray ignores numeric key order when merging.'
        );
    }
}
