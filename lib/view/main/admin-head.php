<?php
/**
 * Copyright (C) 2014-2018 ServMask Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */
?>

<style type="text/css" media="all">
	@font-face {
		font-family: 'trasloco-icons';
		src: url('<?php echo wp_make_link_relative( TRASLOCO_URL ); ?>/lib/view/assets/font/icons.eot?v=<?php echo TRASLOCO_VERSION; ?>');
		src: url('<?php echo wp_make_link_relative( TRASLOCO_URL ); ?>/lib/view/assets/font/icons.eot?v=<?php echo TRASLOCO_VERSION; ?>#iefix') format('embedded-opentype'),
		url('<?php echo wp_make_link_relative( TRASLOCO_URL ); ?>/lib/view/assets/font/icons.woff?v=<?php echo TRASLOCO_VERSION; ?>') format('woff'),
		url('<?php echo wp_make_link_relative( TRASLOCO_URL ); ?>/lib/view/assets/font/icons.ttf?v=<?php echo TRASLOCO_VERSION; ?>') format('truetype'),
		url('<?php echo wp_make_link_relative( TRASLOCO_URL ); ?>/lib/view/assets/font/icons.svg?v=<?php echo TRASLOCO_VERSION; ?>#trasloco-icons') format('svg');
		font-weight: normal;
		font-style: normal;
	}

	[class^="trasloco-icon-"], [class*=" trasloco-icon-"] {
		font-family: 'trasloco-icons';
		speak: none;
		font-style: normal;
		font-weight: normal;
		font-variant: normal;
		text-transform: none;
		line-height: 1;

		/* Better Font Rendering =========== */
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
	}

	.trasloco-icon-notification:before {
		content: "\e619";
	}


</style>
