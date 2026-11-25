<?php
	$theme = array();

	// Theme name
	$theme['name'] = 'Catalog';
	// Description (you can use Tinyboard markup here)
	$theme['description'] = 'Каталог ниток кожної публічної дошки (<board>/catalog.html) і стрічка нових ниток (index.rss). Оновлюється після кожного допису.';
	$theme['version'] = 'nichan';

	// Theme configuration (the boards are all public boards; hidden ones never get a catalog)
	$theme['config'] = Array();

	// Unique function name for building everything
	$theme['build_function'] = 'catalog_build';
