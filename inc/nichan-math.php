<?php

// The bundled converter is dead-guru/latex2mathml-php v1.5.1 (0749731467b5b6a788a4d9190ac7ab60f620449a).
// Its MIT notice and the symbol table's original LPPL notice travel with the library.
function nichan_math_autoload($class) {
	if (preg_match('~^Latex2MathML\\\\((?:Exceptions\\\\)?[A-Za-z]+)$~D', $class, $match)) {
		$path = __DIR__ . '/lib/latex2mathml/' . str_replace('\\', '/', $match[1]) . '.php';
		if (is_file($path)) require_once $path;
	}
}

// Hold formulas outside post markup until all link, quote, whitespace and optional Tidy passes finish.
// A formula quoted in [code] must remain an example of the literal syntax.
function nichan_math_extract($body, &$replacements) {
	$prefix = 'NICHANMATH' . bin2hex(random_bytes(12)) . 'X';
	return preg_replace_callback('~\[code(?:=[^\]\r\n]+)?\].*?\[/code\](*SKIP)(*F)|\[math\](.*?)\[/math\]~is',
		function ($match) use (&$replacements, $prefix) {
			$index = count($replacements);
			$token = $prefix . $index . 'Z';
			$escaped = $match[1]; // markup() has already escaped the entire body.
			$rendered = $index < 12 ? nichan_math_render(html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
			$replacements[$token] = $rendered === null ? '<code class="math-source">[math]' . $escaped . '[/math]</code>' : $rendered;
			return $token;
		}, $body);
}

function nichan_math_render($source) {
	if (PHP_VERSION_ID < 80100 || !class_exists('DOMDocument') || trim($source) === '' || strlen($source) > 2048)
		return null;

	// Count input tokens independently of the converter. This also bounds recursion without braces
	// (e.g. nested radicals) and the maximum number of cells generated for a sparse matrix.
	$tokens = preg_match_all('~\\\\[a-zA-Z]+|[0-9]+(?:\.[0-9]+)?|[^\s]~u', $source);
	if (!$tokens || $tokens > 192) return null;

	static $autoloaded = false;
	if (!$autoloaded) {
		spl_autoload_register('nichan_math_autoload');
		$autoloaded = true;
	}
	// Invalid user input can also produce a PHP warning in the converter. Treat it as a parse
	// failure so a malformed formula cannot abort a post or generate moderator error reports.
	set_error_handler(static function ($level, $message) {
		throw new UnexpectedValueException($message);
	}, E_WARNING | E_NOTICE);
	try {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$math = Latex2MathML\Converter::convert_to_element($source, $doc, 'inline');
		$doc->appendChild($math);
		if (!nichan_math_clean($doc)) return null;
		$math->setAttribute('aria-label', trim($source));
		return '<span class="nichan-formula">' . $doc->saveXML($math) . '</span>';
	} catch (Throwable $e) {
		return null;
	} finally {
		restore_error_handler();
	}
}

// Keep presentation MathML only. User LaTeX can request a link, a style or an excessive dimension.
function nichan_math_clean($doc) {
	static $tags = [
		'math', 'menclose', 'merror', 'mfenced', 'mfrac', 'mi', 'mmultiscripts', 'mn', 'mo', 'mover',
		'mpadded', 'mphantom', 'mprescripts', 'mroot', 'mrow', 'ms', 'mspace', 'msqrt', 'mstyle',
		'msub', 'msubsup', 'msup', 'mtable', 'mtd', 'mtext', 'mtr', 'munder', 'munderover', 'none',
	];
	static $attributes = [
		'accent', 'accentunder', 'align', 'columnalign', 'columnlines', 'display', 'displaystyle',
		'fence', 'form', 'largeop', 'mathvariant', 'movablelimits', 'notation', 'rowalign', 'rowlines',
		'scriptlevel', 'stretchy', 'symmetric',
	];
	static $lengths = ['columnspacing', 'depth', 'height', 'linethickness', 'lspace', 'minsize', 'maxsize',
		'rowspacing', 'rspace', 'voffset', 'width'];
	$elements = $doc->getElementsByTagName('*');
	if ($elements->length > 1200) return false;
	foreach ($elements as $element) {
		if (!in_array($element->tagName, $tags, true)) return false;
		for ($i = $element->attributes->length - 1; $i >= 0; $i--) {
			$attr = $element->attributes->item($i);
			if (in_array($attr->name, $attributes, true) && strlen($attr->value) <= 96 &&
				preg_match('/\A[a-zA-Z0-9 .+-]+\z/', $attr->value)) continue;
			if (in_array($attr->name, $lengths, true) && preg_match('/\A(?:[+-]?(?:[0-9]{1,2}(?:\.[0-9]{1,4})?|\.[0-9]{1,4})(?:em|ex|px|pt)?(?: |$)){1,12}\z/', $attr->value)) continue;
			$element->removeAttributeNode($attr);
		}
	}
	return true;
}
