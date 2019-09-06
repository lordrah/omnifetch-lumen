# OmniFetch for Lumen
OmniFetch for Lumen is a useful library which makes fetch API endpoints easier to set up and flexible enough for different situations. The library allows for easy modification of the response data set on the fly by passing in query parameters as part of the request. These modifications can range from apply filters, embedding related data and pagination to aggregating data with group bys. 

[![Total Downloads](https://img.shields.io/packagist/dt/aros/omnifetch-lumen.svg?style=flat-square)](https://packagist.org/packages/aros/omnifetch-lumen)

## Installation
```
$ composer require aros/omnifetch-lumen
```
## Dependencies
* Lumen >= 5.5

## Main Functionalities

OmniFetch has only two methods that can be used. They are the following:
* **OmniFetch::getSingle**(Illuminate\Database\Eloquent\Builder $builder, array $params) - *used to fetch a single record.*
* **OmniFetch::paginate**(Illuminate\Database\Eloquent\Builder $builder, array $params) - *used to fetch a list of records*

The builder being used should be created using a model (i.e. primary model). e.g. `Author::query()`

## Parameter Options

The **OmniFetch::getSingle** and **OmniFetch::paginate** both require the **$params** in their arguments which is an associative array of options. 

 * **filters** (data type: JSON list /array):
Adds criteria to the query. It can take as many criteria as required. Each criterion is a JSON object (if *filters* is a JSON list, this format is best if it is passed as a request query parameter) or an associative array (if *filters* is an array). It contains the following fields: 
	- *field* (required): the field used for the filtering. It can be a field from the primary model or a related model (which can be done by indicating the relation name specified in the primary model followed by the relation's field. e.g. *author.rating *). It supports nested relations fields e.g. *author.publisher.is_local*.
	- *value* (required): the value to be used. 
	
	> **Note**: if **%** is used in the value it needs to be URL encoded if it is passed as a request query parameter.
	
	- *cond_op* (optional | default:  '*=*'):  the conditional operator used in comparing the field and value. The available operators are: =, !=, >, <, >=, <=, LIKE, IS_NULL (only *field* is needed), IS_NOT_NULL (only *field* is needed).
	- *logical_op* (optional | default: '*AND*'): the logical operator used to combine the remaining filters after it. The available operators are AND and OR.
	
	*Examples*: 
	- `[{"field": "status_id", "value": 1}]` 
	=> `... WHERE primary_table.status_id = 1;`
	- `[{"field": "name", "value": "%25rich%25", "cond_op": "LIKE"}]`
	=> `... WHERE primary_table.name LIKE "%rich%";`
	- `[{"field": "status_id", "value": 1}, {"field": "author.rating": "value": 2.5, "cond_op": ">="}]`
	=> `... WHERE primary_table.status_id = 1 AND author.rating >= 2.5`
	- `[{"field": "likes", "value": 100, "cond_op": ">", "logical_op": "OR"}, {"field": "rating", "value": 4.0, "cond_op": ">="}, {"field": "rating", "value": 4.5, "cond_op": "<="}]`
	=> `... WHERE primary_table.like > 100 OR (primary_table.rating >= 4.0 AND primary_table.rating <= 4.5)`

 * **embeds** (data type: JSON list /array):
Embeds related model data to the primary model being fetched. Only names of relations of the primary model and their relations are allowed (this means that nested relations allowed). 

	 *Examples* :
	 - `["status", "author.publisher"]`
	 
 * **no_pages** (data type: boolean | default is *false*):
Specifies whether not or to paginate.

 * **page** (data type: integer | default: *1*):
Specifies the current page when paginating

 * **page_size** (data type: integer | default: *20*):
Specifies the number of records returned for a page when paginating

 * **order_by** (data type: string):
Specifies the field to order by (relation field are not supported for now).

 * **is_asc** (data type: boolean | default: *true*):
Specifies the ordering direction

 * **aggs** (data type: JSON list /array):
Used for performing aggregations such as *sum*, *min* and *max*. Each aggregation specified in the list is a JSON object (if *aggs* is a JSON list) or associative array (if *aggs* is an array). The following are the fields that each aggregation can have:
	- *field* (required): The field to be aggregated. This can be a field of a relation. Nested relations are allowed. 
	
		> **Note**: In order to use relation fields, the primary model must have the `OmniFetch\HasJoinWith` trait. 
		
	- *alias* (required): The alias used for the aggregation.
	- *func* (required): The aggregation function. The following are available:
		- *count* => `COUNT({{col}})` 
		- *avg* => `AVG({{col}})`
		- *min* => `MIN({{col}})`
		- *max* => `MAX({{col}})`
		- *sum* => `SUM({{col}})`

	*Examples*: 
	- `[{"field": "likes", "func": "sum", "alias": "total_likes"}]` 
	=> `SELECT SUM(primary_table.likes) AS total_likes ...`
	
* **group_by** (data type: JSON list /array): 
Used for grouping data. Each group-by column is represented with a JSON object (if group_by is a JSON list) or associative array (if group_by is an array). The following are the fields that can be used for each group-by:
	- *field* (required): The field to be used for the group-by. This can be a field of a relation. Nested relations are allowed.
	 
		 > **Note**: To use relation fields, the primary model must have the `OmniFetch\HasJoinWith` trait. 

	- *func* (optional): The group-by function. The following are available:
		- *date* => `DATE({{col}})` 
		- *month* => `DATE_FORMAT({{col}}, '%Y-%m')`
		- *year* => `YEAR({{col}})`
	- *alias* (required if *func* is used):  The alias used for the group by.
	
	*Examples*: 
	- `[{"field": "created_at", "func": "date", "alias": "created_date"}]` 
	=> `... GROUP BY DATE(primary_table.created_at) AS created_date`
	
## Usage

This can be explained with an example. In this example, *getOnePost* and *getAllPosts* endpoints are created. The models (or entities) used are:
* *Publisher*: a company which hires *authors* to write *posts*.
* *Author*: a person that writes *posts* and belongs to a *publisher*.
* *Post*: a piece of content written by *authors*.

For this example, *Post* is the primary model while *Author* and *Publisher* are related models.

> **Note:** For the library to be very effective, the model relations should be well set up
---
#### First, lets set up the models (assuming DB migrations and other project prerequisites have been done):
*Publisher Model*
```php
<?php  
  
namespace App\Models;  

use Illuminate\Database\Eloquent\Model;  
  
/**  
 * Class Publisher 
 * @package App\Models
 *   
 * @property integer $id  
 * @property string $name  
 * @property string $address  
 * @property boolean $is_local  
 * @property string $created_at  
 * @property string $modified_at  
 * @property integer $status_id  
 */
class Publisher extends Model  
{  
  protected $table = 'publishers';  
  public $timestamps = false;  
}
```
*Author Model*
```php
<?php  
  
namespace App\Models;  
  
use Illuminate\Database\Eloquent\Model;  
use Illuminate\Database\Eloquent\Relations\BelongsTo;  
  
/**  
 * Class Author 
 * @package App\Models
 *   
 * @property integer $id  
 * @property integer $publisher_id  
 * @property string $first_name  
 * @property string $last_name  
 * @property float $rating  
 * @property string $created_at  
 * @property string $modified_at  
 * @property integer $status_id  
 */
class Author extends Model  
{  
  protected $table = 'authors';  
  public $timestamps = false;  
  
  /**  
  * @return BelongsTo  
  */  
  public function publisher()  
  {  
    return $this->belongsTo(Publisher::class, 'publisher_id');  
  }  
}
```
*Post Model*
```php
<?php  
  
namespace App\Models;  
  
use Illuminate\Database\Eloquent\Model;  
use Illuminate\Database\Eloquent\Relations\BelongsTo;  
use OmniFetch\HasJoinWith;  
  
/**  
 * Class Post 
 * @package App\Models  
 * 
 * @property integer $id  
 * @property integer $author_id  
 * @property string $title  
 * @property string $content  
 * @property float $rating  
 * @property integer $likes  
 * @property string $created_at  
 * @property string $modified_at  
 * @property integer status_id  
 */
class Post extends Model  
{  
  use HasJoinWith;  
  
  protected $table = 'posts';  
  public $timestamps = false;  
  
  /**  
  * @return BelongsTo  
  */  
  public function author()  
  {  
    return $this->belongsTo(Author::class, 'author_id');  
  } 
}
```
---
#### Secondly, lets set up the Controller
```php
<?php  
  
namespace App\Http\Controllers;  
  
use App\Models\Post;  
use Illuminate\Http\Request;  
use OmniFetch\OmniFetch;  
  
class ExampleController extends Controller  
{  
  public function fetchAllPosts(Request $request)  
  {  
    $data = (new OmniFetch())->paginate(Post::query(), $request->query());  
    return response()->json($data);  
  }  
  
  public function fetchOnePost(Request $request, $post_id)  
  {  
    $post = (new OmniFetch())->getSingle(Post::where('id', $post_id), $request->query());  
    return response()->json($post->toArray());  
  }  
}
```
---
#### Finally, add the routes (and we are done!)
```php 
<?php
// routes/web.php

$router->get('/posts', ['uses' => 'ExampleController@fetchAllPosts']);  
$router->get('/posts/{post_id}', ['uses' => 'ExampleController@fetchOnePost']);
```
---
#### Request Examples with Response
* `GET {{base_url}}/posts/1?embeds=["author"]`
	
	*Response*
			<details>
			<summary>Click to show response</summary>
			<p>
	```json
	{
	    "id": 1,
	    "author_id": 101,
	    "title": "Quis ut exercitationem nihil nemo quos aut numquam doloribus.",
	    "content": "Nisi rerum harum reprehenderit. Rem commodi non dolorum repellendus. Quibusdam nobis voluptatibus illum alias voluptatem. Earum dolorem aspernatur quia sint.",
	    "rating": 0.48,
	    "likes": 95,
	    "created_at": "1980-01-18 18:52:07",
	    "modified_at": "1994-02-01 18:58:16",
	    "status_id": 1,
	    "author": {
	        "id": 101,
	        "publisher_id": 26,
	        "first_name": "Mellie",
	        "last_name": "Casper",
	        "rating": 2.47,
	        "created_at": "1995-02-14 08:41:00",
	        "modified_at": "1985-10-23 13:17:36",
	        "status_id": 1
	    }
	}
	```
	</p>	
	</details>

* `GET {{base_url}}/posts?page=3&page_size=2&filters=[{"field": "author.rating", "value": 3.5, "cond_op": ">"}]&embeds=["author.publisher"]&order_by=likes&is_asc=0`

	*Response*
			<details>
			<summary>Click to show response</summary>
			<p>
	```json
	{
	    "pagination": {
	        "total_count": 142,
	        "total_pages": 71,
	        "current_page": 3,
	        "count": 2
	    },
	    "list": [
	        {
	            "id": 325,
	            "author_id": 220,
	            "title": "Tempore aperiam eum itaque voluptates illo dolor.",
	            "content": "Qui nemo delectus iste sequi voluptates impedit beatae. Accusamus eligendi qui tenetur voluptatum maxime. Sapiente blanditiis omnis deserunt suscipit voluptates.",
	            "rating": 1.19,
	            "likes": 2440,
	            "created_at": "2019-04-06 12:32:59",
	            "modified_at": "2019-05-27 11:15:37",
	            "status_id": 1,
	            "author": {
	                "id": 220,
	                "publisher_id": 73,
	                "first_name": "Serena",
	                "last_name": "Bernier",
	                "rating": 4.58,
	                "created_at": "2012-06-21 11:27:25",
	                "modified_at": "2012-06-21 12:36:46",
	                "status_id": 1,
	                "publisher": {
	                    "id": 73,
	                    "name": "Prosacco-Lueilwitz",
	                    "address": "126 Clarissa Wells West Blanca, WY 11801",
	                    "is_local": 0,
	                    "created_at": "2001-04-01 08:02:02",
	                    "modified_at": "2001-04-01 08:02:02",
	                    "status_id": 1
	                }
	            }
	        },
	        {
	            "id": 204,
	            "author_id": 235,
	            "title": "Officia est in exercitationem veniam libero quo sed.",
	            "content": "Commodi tempore a harum aut magni. Ad harum natus minima eos amet. Doloribus sequi veritatis voluptatem sint voluptates deleniti. Id et explicabo et dolores exercitationem et nam.",
	            "rating": 0.67,
	            "likes": 2435,
	            "created_at": "2019-11-05 13:02:07",
	            "modified_at": "2019-11-30 07:38:01",
	            "status_id": 1,
	            "author": {
	                "id": 235,
	                "publisher_id": 91,
	                "first_name": "Alexzander",
	                "last_name": "Kemmer",
	                "rating": 4.64,
	                "created_at": "2001-09-07 23:24:12",
	                "modified_at": "2001-09-07 23:24:12",
	                "status_id": 1,
	                "publisher": {
	                    "id": 91,
	                    "name": "Smitham LLC",
	                    "address": "029 Pierre Greens Apt. 445 South Mallory, SC 88096",
	                    "is_local": 1,
	                    "created_at": "1995-08-15 21:46:30",
	                    "modified_at": "1995-08-15 21:46:30",
	                    "status_id": 1
	                }
	            }
	        }
	    ]
	}
	```
	</p>	
	</details>

* `GET {{base_url}}/posts?aggs=[{"field": "author.rating", "func": "avg", "alias": "average_rating"}]&group_by=[{"field": "author.publisher.name", "alias": "publisher_name"}]&page_size=3`

	> **Note:** When using *aggs* or *group_by* it limits the fields returned to the *aggs* and *group_by* fields

	*Response*
			<details>
			<summary>Click to show response</summary>
			<p>
	```json
	{
	    "pagination": {
	        "total_count": 89,
	        "total_pages": 30,
	        "current_page": 1,
	        "count": 3
	    },
	    "list": [
	        {
	            "publisher_name": "Ankunding Ltd",
	            "average_rating": 1.6022222108311
	        },
	        {
	            "publisher_name": "Bashirian-Altenwerth",
	            "average_rating": 3.2622222635481
	        },
	        {
	            "publisher_name": "Baumbach Ltd",
	            "average_rating": 3.0066666603088
	        }
	    ]
	}
	```
	</p>	
	</details>