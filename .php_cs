<?php

if (class_exists('PhpCsFixer\Finder')) {
    $finder = PhpCsFixer\Finder::create()
        ->in(__DIR__.'/downloader/')
        ->in(__DIR__.'/uploader/')
    ;

	// doc position_after_functions_and_oop_constructs: https://github.com/FriendsOfPHP/PHP-CS-Fixer/blob/4e91f495a7ece1f2566feba2f07cc5824d68ec0b/README.rst
    return PhpCsFixer\Config::create()
        ->setRules(array(
            '@Symfony' => true,
            'no_closing_tag' => true,
            'yoda_style' => false,
            'braces' => [
                'allow_single_line_closure' => true,
                'position_after_functions_and_oop_constructs' => 'same',
                'position_after_anonymous_constructs' => 'same',
                'position_after_control_structures' => 'same'
            ]
        ))
        ->setFinder($finder)
    ;

}
