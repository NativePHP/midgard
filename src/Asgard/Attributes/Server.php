<?php

namespace Native\Api\Client\Asgard\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a method as a `#[Server]` method — one whose body runs on the
 * client and whose real work happens on a server
 * (docs/ASGARD-SERVER-METHODS.md §1).
 *
 *     #[Server]
 *     public function refreshChart(string $range): Closure
 *     {
 *         // Runs on the CLIENT, before anything is sent.
 *         $this->loading = true;
 *         $this->range = $range;
 *
 *         // Runs on the CLIENT, when the server answers.
 *         return function (ServerResponse $response) {
 *             $this->loading = false;
 *         };
 *     }
 *
 * The server half is an ordinary method of the same name on the server
 * component, also carrying this attribute. Two implementations sharing a
 * name: the client one is a local pre-step (optimistic update, loading
 * flag, validation), the server one is the remote work.
 *
 * The attribute is also the allowlist (§8). A method without it is not
 * callable from this path on either side, which is what keeps the
 * callable surface from being "every public method the component
 * happens to have" once a name arrives over a wire.
 *
 * `render` says which of the component's server regions the server redraws
 * after the method has run:
 *
 *     #[Server(render: true)]                    // every region it declares
 *     #[Server(render: 'messages')]              // just this one
 *     #[Server(render: ['messages', 'stats'])]   // these
 *
 * Left out, the answer carries state only, plus whatever refresh() asked
 * for on the server. The request names the regions in `regions`, and the
 * fragments come back with the delta and are painted after it.
 *
 * A supersede/debounce policy for overlapping calls is explicitly out of
 * v1 (§6): compare-and-set reconciliation (§5.2) makes concurrent calls
 * safe without making them ordered, so the policy is an optimisation
 * rather than a correctness fix.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Server
{
    /** @param  bool|string|list<string>  $render */
    public function __construct(public bool|string|array $render = false)
    {
        static::checkRender($render);
    }

    /**
     * Throw unless `$render` is true, false, a region name or a list of
     * region names.
     */
    public static function checkRender(mixed $render): void
    {
        if (is_bool($render)) {
            return;
        }

        $names = is_string($render) ? [$render] : $render;

        if (! is_array($names) || $names === [] || ! array_is_list($names)) {
            throw new InvalidArgumentException('render must be true, false, a region name or a list of region names.');
        }

        foreach ($names as $name) {
            if (! is_string($name) || $name === '' || str_contains($name, '#')) {
                throw new InvalidArgumentException('render names regions, and ['.(is_string($name) ? $name : get_debug_type($name)).'] is not a region name.');
            }
        }
    }
}
