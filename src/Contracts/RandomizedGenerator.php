<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Random\Randomizer;

/**
 * The shape of a replacement value, separated from where its randomness
 * comes from. It deliberately receives no row: a keyed output must depend
 * only on the input value, which is what makes the cross-row and
 * cross-column guarantee of the Keyed value-definition hold. See
 * docs/design/value-generation-primitives/spec, "The value-definition seam".
 */
interface RandomizedGenerator
{
    /**
     * Produce one replacement from $value, drawing randomness only from
     * $random via getInt(). Other Randomizer methods are not permitted: the
     * known-answer test pins exactly that one method.
     */
    public function generate(mixed $value, Randomizer $random): string;

    /**
     * Stable identity of this shape and its parameters, e.g. "keepLast:4".
     * Never contains row data.
     */
    public function signature(): string;
}
