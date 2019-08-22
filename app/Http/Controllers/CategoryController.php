<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Category;
class CategoryController extends Controller
{
    public function index(){
      $categories = Category::all();
      return  response()->json($categories);
      //return View::make('category/index')->with('categories', $categories);
    }

    public function show($id){

      $category = Category::find($id);
      return response()->json($category);
      //return View::make('category/show')->with('category', $category)
    }

    public function create(Request $request){
 
      $validatedData = $request->validate([
        'name' => 'string|required|max:50',
        'description' => 'string|required|max:250',
      ]);

      Category::create($validatedData);
      return response()->json("category created !"); 
    }

    public function update(Request $request,$id){

      $validatedUpdatedData = $request->validate([
        'name' => 'string|required|max:50',
        'description' => 'string|required|max:250',
      ]);

      $category = Category::find($id);

      $category->name = $validatedUpdatedData["name"];
      $category->description = $validatedUpdatedData["description"];
      $category->save();
      return response()->json("category updated !"); 
    }

    public function delete($id){

      $category = Category::find($id);
      $category->delete();
      return response()->json("category deleted !");
    }


}
