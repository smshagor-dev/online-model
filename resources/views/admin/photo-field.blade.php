<div class="photo-field" data-photo-field>
    <label for="avatar_upload">Profile photo</label>
    <div class="photo-control">
        <div class="photo-image-wrap">
            @if(!empty($avatar))<img class="profile-photo" data-photo-preview src="{{ $avatar }}" alt="Current profile photo">@else<img class="profile-photo" data-photo-preview alt="Selected profile photo preview" hidden>@endif
        </div>
        <div class="photo-input-wrap">
            <input id="avatar_upload" name="avatar_upload" type="file" accept="image/png,image/jpeg,image/webp" data-photo-input aria-describedby="photo-help">
            <button type="button" class="secondary" data-photo-clear>Clear selected photo</button>
        </div>
    </div>
    <input type="hidden" name="avatar" data-photo-value>
    <p class="photo-guidance" id="photo-help">PNG, JPEG or WebP. Images up to 10 MB are resized locally to a maximum of 512 × 512 pixels and 96 KB. Without JavaScript, upload an image already within those limits.</p>
    <p role="alert" data-photo-error hidden></p>
    @if(!empty($avatar))<label class="check-line"><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</label>@endif
</div>
