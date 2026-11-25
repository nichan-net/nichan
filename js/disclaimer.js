function notice() {
   
       if ("1" == localStorage.notice)
		document.getElementById("bottom").style.display = "none";
		
        
    else {
        var a = document.getElementById("bottom");
        a.style.display = "block";
        document.getElementById("accept").onclick = function() {
            a.style.display = "none";
            localStorage.notice = "1";
            notice()
        }
        ;
        document.getElementById("notaccept").onclick = function() {
            document.location.href = "/"
        }
    }
}
notice();