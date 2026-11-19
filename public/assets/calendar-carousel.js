(function() {
  var wrapper = document.getElementById("wrapper");
  if (wrapper && wrapper.children.length > 2) {
    setInterval(function() {
      var firstSlide = wrapper.firstElementChild;
      firstSlide.style.transition = "margin-left 1.8s ease-in-out";
      firstSlide.style.marginLeft = "-50%";
      setTimeout(function() {
        firstSlide.style.transition = "none";
        firstSlide.style.marginLeft = "0";
        wrapper.appendChild(firstSlide);
      }, 1800);
    }, 5300);
  }
})();
