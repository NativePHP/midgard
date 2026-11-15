<native:column fill>
    <native:text>Count {{ $count }}</native:text>
    @server('stats')
        <native:text>Loading stats…</native:text>
    @endserver
    @server('feed')
        <native:text>Loading feed…</native:text>
    @endserver
    <native:text @tap="bump" ref="bump">Bump</native:text>
</native:column>
