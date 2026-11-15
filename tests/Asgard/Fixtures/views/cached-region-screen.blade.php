<native:column fill>
    <native:text>Range {{ $range }}</native:text>
    @server('stats')
        <native:text>Loading stats…</native:text>
    @endserver
    @server('news', cacheTtl: $newsTtl)
        <native:text>Loading news…</native:text>
    @endserver
</native:column>
