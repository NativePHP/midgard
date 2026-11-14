<native:column fill>
    <native:text>Draft {{ $draft }}</native:text>
    @server('stats')
        <native:text>Loading stats…</native:text>
    @endserver
    @server('feed')
        <native:text>Loading feed…</native:text>
    @endserver
    <native:text @tap="tapped" ref="tap">Taps {{ $taps }}</native:text>
    <native:text @tap="typed('hello')" ref="type">Type</native:text>
</native:column>
