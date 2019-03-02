<?php

namespace App\Http\Controllers;

use App\{
  DiscoverCategory,
  Follower,
  Hashtag,
  Profile,
  Status, 
  StatusHashtag, 
  UserFilter
};
use Auth, DB, Cache;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function home(Request $request)
    {
        return view('discover.home');
    }

    public function showTags(Request $request, $hashtag)
    {
        $this->validate($request, [
          'page' => 'nullable|integer|min:1|max:20',
        ]);

        $tag = Hashtag::whereSlug($hashtag)
          ->firstOrFail();

        $page = $request->input('page') ?? 1;
        $key = 'discover:tag-'.$tag->id.':page-'.$page;
        $keyMinutes = $page > 1 ? 5 : 2;

        $posts = Cache::remember($key, now()->addMinutes($keyMinutes), function() use ($tag, $request) {
          return $tag->posts()
          ->whereNull('url')
          ->whereNull('uri')
          ->whereHas('media')
          ->withCount(['likes', 'comments'])
          ->whereIsNsfw(false)
          ->whereVisibility('public')
          ->orderBy('id', 'desc')
          ->simplePaginate(24);
        });

        if($posts->count() == 0) {
          abort(404);
        }
        
        return view('discover.tags.show', compact('tag', 'posts'));
    }

    public function showCategory(Request $request, $slug)
    {
      $tag = DiscoverCategory::whereActive(true)
        ->whereSlug($slug)
        ->firstOrFail();

      $posts = Cache::remember('discover:category-'.$tag->id.':posts', now()->addMinutes(15), function() use ($tag) {
          $tagids = $tag->hashtags->pluck('id')->toArray();
          $sids = StatusHashtag::whereIn('hashtag_id', $tagids)->orderByDesc('status_id')->take(500)->pluck('status_id')->toArray();
          $posts = Status::whereScope('public')->whereIn('id', $sids)->whereNull('uri')->whereType('photo')->whereNull('in_reply_to_id')->whereNull('reblog_of_id')->orderByDesc('created_at')->take(39)->get();
          return $posts;
      });
      $tag->posts_count = Cache::remember('discover:category-'.$tag->id.':posts_count', now()->addMinutes(30), function() use ($tag) {
        return $tag->posts()->whereScope('public')->count();
      });
      return view('discover.tags.category', compact('tag', 'posts'));
    }

    public function showPersonal(Request $request)
    {
      $profile = Auth::user()->profile;

      $tags = Cache::remember('profile-'.$profile->id.':hashtags', now()->addMinutes(15), function() use ($profile){
          return $profile->hashtags()->groupBy('hashtag_id')->inRandomOrder()->take(8)->get();
      });
      $following = Cache::remember('profile:following:'.$profile->id, now()->addMinutes(60), function() use ($profile) {
          $res = Follower::whereProfileId($profile->id)->pluck('following_id');
          return $res->push($profile->id)->toArray();
      });
      $posts = Cache::remember('profile-'.$profile->id.':hashtag-posts', now()->addMinutes(5), function() use ($profile, $following) {
          $posts = Status::whereScope('public')->withCount(['likes','comments'])->whereNotIn('profile_id', $following)->whereHas('media')->whereType('photo')->orderByDesc('created_at')->take(39)->get();
          $posts->post_count = Status::whereScope('public')->whereNotIn('profile_id', $following)->whereHas('media')->whereType('photo')->count();
          return $posts;
      });
      return view('discover.personal', compact('posts', 'tags'));
    }
}
