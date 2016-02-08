<?php
/*
Plugin Name: Recipes parser from web to wordpress  
Plugin URI: http://bananagarden.net
Description: парсим рецепты и добавляем их как кастомные записи 
Author: http://bananagarden.net
Author URI: http://bananagarden.net
*/

add_action(  'wp_dashboard_setup',  'add_recipe_uploader_widget'  );
// вызываем функцию для создания консольного виджета 
function add_recipe_uploader_widget() {

	wp_add_dashboard_widget('recipe_widget',
									'Загрузчик рецептов из json',  
									'recipe_uploader_widget'  
									);
}

// функция для отображения содержания консольного виджета 
function recipe_uploader_widget() {

	echo '<form method="post" style="text-align: center;">';
	echo '<input name="recipe_parser_submit" type="submit" class="button button-primary" value="Start parser"> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
	echo '<input name="recipe_uploader_submit" type="submit" class="button button-primary" value="Upload recipes"><br>';

	
	echo '</form>';
}


add_action( 'init', 'save_new_recipe' );
add_action( 'init', 'parse_recipes' );


function save_new_recipe() {

	if( 'POST' == $_SERVER['REQUEST_METHOD'] && isset( $_POST['recipe_uploader_submit'] ) ) {

		$dir = get_template_directory() . '/recipes';
		$recipe_list = scandir( $dir );

		/**
		 * 	remove . and .. value in array 
		 */
		unset($recipe_list[0]);
		unset($recipe_list[1]);

		foreach ($recipe_list as $recipe ) {
			$recipe = file_get_contents( $dir . '/' . $recipe);
			$recipe = json_decode( $recipe, true );

			$rec = array(
				'post_title' => $recipe['title'], 
				'post_status' => 'publish',
				'post_name' => $recipe['link'],
				'post_author' => 1,
				'post_type' => 'recipes'
			);

			$recipe_id = wp_insert_post( $rec );

			wp_set_object_terms( $recipe_id, strtolower($recipe['category']), 'ingredients', false); //set taxonomies

			update_post_meta( $recipe_id, '_recipe_second_header', trim($recipe['subtitle']) );
			update_post_meta( $recipe_id, '_recipe_description', trim($recipe['desc']) );
			update_post_meta( $recipe_id, '_recipe_main_photo', trim($recipe['headbg']) );
			update_post_meta( $recipe_id, '_recipe_thumb', trim($recipe['thumb']) );
			update_post_meta( $recipe_id, '_recipe_ing_photo',	trim($recipe['ingbg']) );
			update_post_meta( $recipe_id, '_recipe_portions', trim($recipe['portions']) );
			update_post_meta( $recipe_id, '_recipe_calories', trim($recipe['calories']) );
			update_post_meta( $recipe_id, '_recipe_cooktime', trim($recipe['time']) );

			$i = 1;

			foreach ( $recipe['ingredients'] as $ing ) {
				update_post_meta( $recipe_id, '_recipe_ing' . $i, trim($ing) );
				$i++;
			}

			$i = 1;

			foreach ( $recipe['steps'] as $step ) {

				$step_name = 'recipe_step_' . $i . '_name';
				$step_description = 'recipe_step_' . $i . '_description';
				$step_photo = 'recipe_step_' . $i . '_photo';
				$i++;

				update_post_meta( $recipe_id, '_' . $step_name, trim($step['title'] ));
				update_post_meta( $recipe_id, '_' . $step_description, trim($step['desc'] ));
				update_post_meta( $recipe_id, '_' . $step_photo, trim($step['img'])  );
			}
		}
	}
}

function parse_recipes() {

	if( 'POST' == $_SERVER['REQUEST_METHOD'] && isset( $_POST['recipe_parser_submit'] ) ) {
		require 'simple_html_dom.php';


		$homeURL = 'https://www.blueapron.com';
		$cookbookURL = 'https://www.blueapron.com/cookbook/';


		/**
		 * find all category 
		 */

		$html = file_get_html($cookbookURL);

		foreach($html->find('#filter-ingredients li') as $cats) {

			$cat = trim($cats->plaintext);


			/**
			 * set current category as link 
			 * and get recipes
			 */

			$recipes = file_get_html($cookbookURL . $cat);


			foreach($recipes->find('.recipe-thumb') as $element) {

				/**
				 * get some data from thumb
				 * and go to single recipe
				 */
				
				$rec["category"] = $cat;				
				$link = $element->find('a', 0)->href;				
				$rec["link"] = preg_replace( "/^.{9}/", "", $link );		
				$rec["thumb"] = $element->find('img', 0)->src;

				/**
				 * now we in single recipe, 
				 * so lets get all we want and go to another one  
				 */

				$recipe = file_get_html( $homeURL . $link );

				/**
				 * check for 404 error  
				 */
				if ($recipe) {

					$rec["title"] = $recipe->find('.main-title', 0)->plaintext;
					$rec["subtitle"] = $recipe->find('.sub-title', 0)->plaintext;
					$rec["headbg"] = $recipe->find('.rec-splash-img', 0)->src;
					$rec["portions"] = $recipe->find('.recipe-servings p', 0)->plaintext;
					$rec["calories"] = $recipe->find('.recipe-servings p', 1)->plaintext;

					/**
					 * in some recipe there is no time field, 
					 * and parser write description to time variable
					 * bullshit!
					 * so we need this check
					 * and if there is not.. we set default value
					 */

					$time = $recipe->find('.recipe-servings p', 2)->plaintext;
					if (strlen( $time) > 100 ) {
						$rec["time"] = $time;
					} else {
						$rec["time"] = 'Cooking Time: so fast as you can!';
					}			

					$rec["desc"] = $recipe->find('.rec-descrip-details-section', 0)->plaintext;
					$rec["ingbg"] = $recipe->find('.ingredients-img', 0)->src;

					/**
					 * we need more loop to collect all data 
					 * now we get all ingredients as no-associated array
					 */

					foreach ($recipe->find('.ingredients-list li') as $i) {
						$in[] = $i->plaintext;
					}

					/**
					 * and set it as a value of our main output array 
					 */

					$rec["ingredients"] = $in;
					unset($in);

					/**
					 * the same for recipe steps
					 */		

					foreach ($recipe->find('.section-rec-instructions .instr-step') as $i) {
						$stps["img"] = $i->find('.img-max', 0)->src;
						$stps["title"] = $i->find('.instr-title', 0)->plaintext;
						$stps["desc"] = $i->find('.instr-txt', 0)->plaintext;

						$steps[] = $stps;
					}

					$rec["steps"] = $steps;
					unset($steps);
				}

				/**
				 * write single recipe data in to the file named as rec-name.json 
				 */
				
				file_put_contents( '/var/www/lab/public_html/parser/data/' . $link . '.json', json_encode($rec));

				/**
				 * I lie, that the output array 
				 */

				//$data[] = $rec;
				

				}

		}
	}
}
