# abcnorio/custom-func

Custom functionality plugin for abcnorio.org.

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, seeded editorial data, capability sync, and headless admin behavior.

A lot of configuration that isnt expected to change frequently, or outside of feature development is managed through environmental variables served as part of a larger "ABCNoRio Stack" project. 
