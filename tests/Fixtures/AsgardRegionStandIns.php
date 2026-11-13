<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

// Stand-ins for the parts of nativephp/mobile that HttpFragmentSource talks
// to, which is not a dev dependency of this package. A real install already
// has them. ServerRegion here only records what it was handed.
if (! interface_exists(FragmentSource::class)) {
    interface FragmentSource {}
}

namespace Native\Mobile\Edge\Asgard;

if (! class_exists(RegionRequest::class)) {
    class RegionRequest
    {
        public function __construct(
            public readonly string $id,
            public readonly string $component,
            public readonly string $region,
            public readonly array $state = [],
            public readonly string $platform = 'ios',
        ) {}

        public function toArray(): array
        {
            return ['component' => $this->component, 'region' => $this->region, 'state' => $this->state, 'platform' => $this->platform];
        }
    }
}

if (! class_exists(ActionRequest::class)) {
    class ActionRequest extends RegionRequest
    {
        public function __construct(string $id, string $component, string $region, public readonly string $call = 'go')
        {
            parent::__construct($id, $component, $region);
        }
    }
}

if (! class_exists(RegionBatch::class)) {
    class RegionBatch
    {
        /** @param list<RegionRequest> $requests */
        public function __construct(public readonly array $requests) {}

        /** @return list<string> */
        public function regions(): array
        {
            return array_map(fn (RegionRequest $r) => $r->region, $this->requests);
        }

        public function toArray(): array
        {
            $first = $this->requests[0];

            return ['component' => $first->component, 'regions' => $this->regions(), 'state' => $first->state, 'platform' => $first->platform];
        }
    }
}

if (! class_exists(ServerRegion::class)) {
    class ServerRegion
    {
        public const TIMEOUT = 'timeout';

        public const NETWORK = 'network';

        public const SERVER = 'server';

        public const INVALID = 'invalid';

        /** @var list<array> */
        public static array $calls = [];

        public static function deliver(RegionRequest $request, array|string $tree, ?array $chrome = null, ?string $version = null, array $fragments = [], array $refresh = []): bool
        {
            static::$calls[] = ['deliver', $request->id, $tree, $chrome, $version, $fragments, $refresh];

            return true;
        }

        public static function deliverBatch(RegionBatch $batch, array $fragments): int
        {
            static::$calls[] = ['deliverBatch', $batch->regions(), $fragments];

            return count($fragments);
        }

        public static function failedBatch(RegionBatch $batch, string $reason, ?int $status = null, ?string $message = null): int
        {
            static::$calls[] = ['failedBatch', $batch->regions(), $reason, $status, $message];

            return count($batch->requests);
        }

        public static function failed(RegionRequest $request, string $reason, ?int $status = null, ?string $message = null): bool
        {
            static::$calls[] = ['failed', $request->id, $reason, $status, $message];

            return true;
        }
    }
}
