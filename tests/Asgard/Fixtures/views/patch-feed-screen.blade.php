<native:column fill>
    <native:text>Tab {{ $tab }}</native:text>
    @server('feed')
        <native:text>Loading feed…</native:text>
    @endserver
</native:column>
