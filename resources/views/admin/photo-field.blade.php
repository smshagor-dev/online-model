<div data-photo-field>
    <label for="avatar_upload">Profile photo</label>
    @if(!empty($avatar))<img class="profile-photo" data-photo-preview src="{{$avatar}}" alt="Current profile photo">@else<img class="profile-photo" data-photo-preview alt="New profile photo preview" hidden>@endif
    <input id="avatar_upload" name="avatar_upload" type="file" accept="image/png,image/jpeg,image/webp" data-photo-input>
    <button type="button" class="secondary" data-photo-clear>Clear selected photo</button>
    <input type="hidden" name="avatar" data-photo-value>
    <p><small>PNG, JPEG or WebP. Photos up to 10 MB are resized in your browser to 512 × 512 pixels and 96 KB. Without JavaScript, upload an image already within those limits.</small></p>
    <p role="alert" data-photo-error hidden></p>
    @if(!empty($avatar))<label><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</label>@endif
</div>
