<native:column>
    <native:text>Rolled {{ $roll }}</native:text>
    @server('stats')
        <native:text>Loading stats</native:text>
    @endserver
</native:column>
