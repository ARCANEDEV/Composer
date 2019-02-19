<?php namespace Arcanedev\Composer\Utilities;

/**
 * Class     NestedArray
 *
 * @package  Arcanedev\Composer\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class NestedArray
{
    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is similar to PHP's array_merge_recursive() function, but it handles non-array values differently.
     * When merging values that are not both arrays, the latter value replaces the former rather than merging with it.
     *
     *
     * @param  array  $arrays
     *
     * @return array
     *
     * @see NestedArray::mergeDeepArray()
     */
    public static function mergeDeep(...$arrays)
    {
        return self::mergeDeepArray($arrays);
    }

    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is equivalent to NestedArray::mergeDeep(), except the input arrays are passed as a single array
     * parameter rather than a variable parameter list.
     *
     * @param  array  $arrays
     * @param  bool   $preserveIntegerKeys
     *
     * @return array
     *
     * @see NestedArray::mergeDeep()
     */
    public static function mergeDeepArray(array $arrays, $preserveIntegerKeys = false)
    {
        $result = [];

        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                // Renumber integer keys as array_merge_recursive() does unless $preserve_integer_keys is set to TRUE.
                // Note that PHP automatically converts array keys that are integer strings (e.g., '1') to integers.
                if (is_integer($key) && ! $preserveIntegerKeys) {
                    $result[] = $value;
                }
                elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                    // Recurse when both values are arrays.
                    $result[$key] = self::mergeDeepArray([$result[$key], $value], $preserveIntegerKeys);
                }
                else {
                    // Otherwise, use the latter value, overriding any previous value.
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }
}
