<?php
/**
 * Player Gestures
 */
if (!defined('ABSPATH')) exit;

?>
<!-- Gestures -->
<media-gesture event="pointerup" action="toggle:paused"></media-gesture>
<media-gesture event="dblpointerup" action="toggle:fullscreen"></media-gesture>
<media-gesture event="pointerup" action="toggle:controls"></media-gesture>
<media-gesture event="dblpointerup" action="seek:-10"></media-gesture>
<media-gesture event="dblpointerup" action="seek:10"></media-gesture>
