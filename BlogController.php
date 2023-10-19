<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRequest;
use App\Models\Blog;
use App\Models\Category;
use App\Models\meta_data;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nette\Utils\Image;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class BlogController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $order = $request->get("order") ?? "asc";
        $blogs = Blog::orderBy('title', $order )->latest()->get();
        return view('blogs.index', compact('blogs'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */

    public function create()
    {
        $categories = Category::all();
        return view('blogs.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Response
     */
    public function store(StoreRequest $request)
    {
        try {
            $validated = $request->validated();
            $validated['url'] = Storage::disk('s3')->put(env('AWS_FOlDER'), $validated['url']);
            $validated['status'] = $request->status == 'on' ? 1 : 0;
            $validated['slug'] = Str::slug($request->slug);
            $validated['description'] = $this->extract_images_from_description_and_upload_to_s3($request['description']);

            $blog = Blog::create($validated);
            $blog->categories()->attach($validated['categories']);
        } catch (Exception $e) {
            return redirect()->back()-> withErrors(['msg' => $e->getMessage()]);
        }
        return redirect()->route('blogs.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return Response
     */

    public function edit(Blog $blog)
    {
        $categories = Category::select('id', 'name')->get();
        return view('blogs.edit', compact('blog', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(StoreRequest $request, Blog $blog)
    {

        try {
            $validated = $request->validated();
            $validated['status'] = $request->status == 'on' ? 1 : 0;
            $validated['slug'] = Str::slug($request->slug);
            if ($request->hasFile('url')) {
                $path = Storage::disk('s3')->put(env('AWS_FOlDER'), $validated['url']);
                if (Storage::disk('s3')->exists($blog->url)) {
                    Storage::disk('s3')->delete($blog->url);
                }
                $validated['url'] = $path;
            } else {
                $validated['url'] = $blog->url;
            }
//            $validated['url'] = 'I4FFfIlRhr0lp4pLMWHqboAuk78xWsw3OaBsbL28.webp';
            $validated['description'] = $this->extract_images_from_description_and_upload_to_s3($request['description']);
            $blog->update($validated);
            $blog->categories()->sync($validated['categories']);
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['msg' => $e->getMessage()]);
        }
        return redirect()->route('blogs.index');
    }


    private function extract_images_from_description_and_upload_to_s3($description) {
        preg_match_all('/<img src="data[^>]+>/i',$description, $base64_images);
        $upload_able_images = [];
        foreach ($base64_images[0] as $key => $img) {
            preg_match('/src="([^"]+)/i',$img, $get_prouned_img);
            $upload_able_images[] = str_ireplace( 'src="', '',  $get_prouned_img[0]);
        }
        foreach ($upload_able_images as $key => $image){
            $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '',$image));
            $image_url = env('AWS_FOlDER').'/blog_images/blog_content_images_'.$key.'_'.time().'.'.'png';
            if(Storage::disk('s3')->put($image_url, $data)) {
                $url = env('AWS_CDN').'/'. $image_url;
                $description = str_ireplace( $image, $url,  $description);
            }
        }
        return $description;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return Response
     */

    public function destroy(Blog $blog)
    {
        $blog->categories()->detach();
        $blog->delete();
        return redirect()->route('blogs.index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getBlogs()
    {
        if (request()->has('search') != '') {
            $search = request()->get('search');
            $blogs = Blog::where('title', 'LIKE', "%$search%")->latest()->get();
        } else {
            $blogs = Blog::with('categories')->whereStatus(1)->latest()->get();
        }
        $meta['meta_image_url'] = asset('images/crain.webp');
        $tag = meta_data::wherePage('blog')->first();
        $meta['meta_title'] = $tag->meta_title;
        $meta['meta_description'] = $tag->meta_description;
        $meta['meta_keywords'] = $tag->meta_keywords;
        $meta['blogs'] = $blogs;
        return view('website.blogs')->with($meta);
    }

    /**
     *Blog detail
     */
    public function blogDetail($slug)
    {
        $data['blog'] = Blog::with('categories')->whereSlug($slug)->first();
        $data['meta_title'] = $data['blog']->title;
        $data['meta_description'] =$data['blog']->meta_description;
        $data['meta_keywords'] = $data['blog']->keywords;
        $data['meta_image_url'] = $data['blog']->image_url;
        return view('website.blog_detail', $data);
    }


}
