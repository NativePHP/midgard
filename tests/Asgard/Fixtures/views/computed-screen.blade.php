<native:column fill>
    <native:text>Label {{ $this->label }} doubled {{ $this->doubled }}</native:text>
    @server('stats')
        <native:text>Loading stats…</native:text>
    @endserver
    @server('feed')
        <native:text>Loading feed…</native:text>
    @endserver
    <native:text @tap="tapped" ref="tap">Taps {{ $taps }}</native:text>
</native:column>
