/* Video facade: the iframe is created on click and never before — that is the
   whole contract (video_facade.twig explains why the facade exists at all).
   One pass over every facade on the page; each keeps its own listener, so two
   videos on one page respond independently. */
document.querySelectorAll('.video-facade').forEach(function (box) {
  var button = box.querySelector('.video-play');
  if (!button) return;

  button.addEventListener('click', function () {
    var frame = document.createElement('iframe');
    frame.src = box.dataset.videoSrc;
    frame.allow = 'accelerometer; autoplay; encrypted-media; picture-in-picture';
    frame.allowFullscreen = true;
    var title = box.querySelector('.sr-only');
    frame.title = title ? title.textContent : 'Video';
    box.replaceChildren(frame);
  });
});
